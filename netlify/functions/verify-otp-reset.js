const admin = require('firebase-admin');

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
    const { email, otp, newPassword } = JSON.parse(event.body);
    if (!email || !otp || !newPassword) {
      return {
        statusCode: 400,
        headers,
        body: JSON.stringify({ error: 'Email, OTP code, and new password are all required.' })
      };
    }

    if (newPassword.length < 6) {
      return {
        statusCode: 400,
        headers,
        body: JSON.stringify({ error: 'Password must be at least 6 characters long.' })
      };
    }

    // Get OTP from Firestore
    const otpDocRef = db.collection('password_reset_otps').doc(email);
    const otpDoc = await otpDocRef.get();

    if (!otpDoc.exists) {
      return {
        statusCode: 400,
        headers,
        body: JSON.stringify({ error: 'No active OTP verification session found for this email. Please request a new code.' })
      };
    }

    const { otp: savedOtp, expires_at: expiresAt } = otpDoc.data();

    // Verify OTP code
    if (savedOtp !== otp.trim()) {
      return {
        statusCode: 400,
        headers,
        body: JSON.stringify({ error: 'Invalid verification OTP code. Please check and try again.' })
      };
    }

    // Verify expiration
    if (Date.now() > expiresAt) {
      // Clean up expired OTP
      await otpDocRef.delete();
      return {
        statusCode: 400,
        headers,
        body: JSON.stringify({ error: 'Verification code (OTP) has expired. Please request a new code.' })
      };
    }

    // Get user UID by email
    let userRecord;
    try {
      userRecord = await admin.auth().getUserByEmail(email);
    } catch (authError) {
      return {
        statusCode: 404,
        headers,
        body: JSON.stringify({ error: 'User account not found.' })
      };
    }

    // Update password in Firebase Auth
    await admin.auth().updateUser(userRecord.uid, {
      password: newPassword
    });

    // Delete OTP document from Firestore
    await otpDocRef.delete();

    return {
      statusCode: 200,
      headers,
      body: JSON.stringify({ message: 'Password has been successfully reset! You can now log in with your new password.' })
    };

  } catch (error) {
    console.error('Error verifying OTP and resetting password:', error);
    return {
      statusCode: 500,
      headers,
      body: JSON.stringify({ error: error.message })
    };
  }
};
