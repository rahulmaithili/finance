const admin = require('firebase-admin');
const nodemailer = require('nodemailer');

// Initialize Firebase Admin once
if (!admin.apps.length) {
  try {
    admin.initializeApp({
      credential: admin.credential.cert({
        projectId: process.env.FIREBASE_PROJECT_ID,
        clientEmail: process.env.FIREBASE_CLIENT_EMAIL,
        // Replace escaped newlines in private key
        privateKey: process.env.FIREBASE_PRIVATE_KEY ? process.env.FIREBASE_PRIVATE_KEY.replace(/\\n/g, '\n') : undefined,
      }),
    });
  } catch (error) {
    console.error('Firebase admin initialization failed:', error);
  }
}

const db = admin.firestore();

exports.handler = async (event, context) => {
  // CORS Preflight headers
  const headers = {
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Headers': 'Content-Type',
    'Access-Control-Allow-Methods': 'POST, OPTIONS',
    'Content-Type': 'application/json'
  };

  if (event.httpMethod === 'OPTIONS') {
    return { statusCode: 200, headers, body: '' };
  }

  if (event.httpMethod !== 'POST') {
    return { statusCode: 405, headers, body: JSON.stringify({ error: 'Method Not Allowed' }) };
  }

  try {
    const { email } = JSON.parse(event.body);
    if (!email) {
      return {
        statusCode: 400,
        headers,
        body: JSON.stringify({ error: 'Email is required' })
      };
    }

    // Verify user exists in Firebase Auth
    let userRecord;
    try {
      userRecord = await admin.auth().getUserByEmail(email);
    } catch (authError) {
      if (authError.code === 'auth/user-not-found') {
        return {
          statusCode: 404,
          headers,
          body: JSON.stringify({ error: 'No user account found with this email address.' })
        };
      }
      throw authError;
    }

    // Generate random 6-digit OTP
    const otp = Math.floor(100000 + Math.random() * 900000).toString();
    const expiresAt = Date.now() + 10 * 60 * 1000; // 10 minutes from now

    // Store in Firestore
    await db.collection('password_reset_otps').doc(email).set({
      otp,
      expires_at: expiresAt
    });

    // Send email using Nodemailer SMTP
    const smtpEmail = process.env.SMTP_EMAIL;
    const smtpPass = process.env.SMTP_PASSWORD;

    if (!smtpEmail || !smtpPass) {
      return {
        statusCode: 500,
        headers,
        body: JSON.stringify({ error: 'SMTP server environment variables (SMTP_EMAIL, SMTP_PASSWORD) are not configured in Netlify.' })
      };
    }

    const transporter = nodemailer.createTransport({
      service: 'gmail',
      auth: {
        user: smtpEmail,
        pass: smtpPass
      }
    });

    const mailOptions = {
      from: `"IEMS ERP Security" <${smtpEmail}>`,
      to: email,
      subject: 'IEMS ERP - Password Reset Verification Code',
      html: `
        <div style="font-family: Arial, sans-serif; background-color: #0f172a; color: #f8fafc; padding: 40px; border-radius: 16px; max-width: 500px; margin: 0 auto; border: 1px solid #1e293b;">
          <h2 style="color: #6366f1; text-align: center; margin-top: 0; font-size: 1.5rem; font-weight: 800;">Password Recovery Verification</h2>
          <p style="font-size: 0.95rem; line-height: 1.6; color: #94a3b8; text-align: center;">You requested a password reset for your account. Please use the following 6-digit verification code (OTP) to reset your password:</p>
          <div style="text-align: center; margin: 30px 0;">
            <span style="font-size: 2.2rem; font-weight: 800; letter-spacing: 6px; color: #fff; background-color: #1e1b4b; border: 2px dashed #6366f1; padding: 12px 24px; border-radius: 12px; display: inline-block;">${otp}</span>
          </div>
          <p style="font-size: 0.8rem; line-height: 1.5; color: #64748b; text-align: center; margin-bottom: 0;">This code is valid for 10 minutes. If you did not make this request, you can safely ignore this email.</p>
        </div>
      `
    };

    await transporter.sendMail(mailOptions);

    return {
      statusCode: 200,
      headers,
      body: JSON.stringify({ message: 'OTP sent successfully to Gmail.' })
    };

  } catch (error) {
    console.error('Error sending OTP:', error);
    return {
      statusCode: 500,
      headers,
      body: JSON.stringify({ error: error.message })
    };
  }
};
