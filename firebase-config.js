// Firebase SDK Configuration & Initialization
// Replace these placeholders with your actual Firebase Project Configuration details from Firebase Console.

const firebaseConfig = {
    apiKey: "AIzaSyC5Vj2DrewJ-rEQHDANjibtVYJ4Bk4xF2w",
    authDomain: "finance-163ac.firebaseapp.com",
    projectId: "finance-163ac",
    storageBucket: "finance-163ac.firebasestorage.app",
    messagingSenderId: "922717138410",
    appId: "1:922717138410:web:9467f989b574e3fed1d693"
};

// Initialize Firebase (Compat mode)
if (!firebase.apps.length) {
    firebase.initializeApp(firebaseConfig);
}

// Global instances
const auth = firebase.auth();
const db = firebase.firestore();
const storage = firebase.storage();

// Helper: Check Auth Session state
function checkAuthState(callback) {
    auth.onAuthStateChanged((user) => {
        if (!user) {
            // Not logged in, redirect to login page
            window.location.href = 'login.html';
        } else {
            if (callback) callback(user);
        }
    });
}

// Helper: Format Currency (₹)
function formatCurrency(amount) {
    return '₹' + parseFloat(amount).toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

// Helper: Log user actions to audit trail
function logActivity(actionText) {
    const user = auth.currentUser;
    if (user) {
        db.collection('users').doc(user.uid).get().then(doc => {
            const uData = doc.data();
            db.collection('activity_logs').add({
                user_id: user.uid,
                user_name: uData ? uData.full_name : user.email.split('@')[0],
                user_email: user.email,
                user_role: uData ? uData.role : 'staff',
                action: actionText,
                ip_address: '127.0.0.1',
                created_at: firebase.firestore.FieldValue.serverTimestamp()
            });
        }).catch(err => console.log("Failed to log activity: ", err));
    }
}

