// =====================================================================
// TC LIMITED - Firebase Realtime Synchronization & Event Bus
// =====================================================================

const FirebaseSync = {
    initialized: false,
    db: null,
    listeners: [],
    lastEventTimestamp: Date.now(),

    init(firebaseConfig) {
        console.log('[FirebaseSync] Initializing real-time synchronization bus...');
        
        // Check if Firebase JS SDK is loaded
        if (typeof firebase !== 'undefined' && firebaseConfig && firebaseConfig.apiKey) {
            try {
                if (!firebase.apps.length) {
                    firebase.initializeApp(firebaseConfig);
                }
                this.db = firebase.database();
                this.listenToFirebase();
                console.log('[FirebaseSync] Connected to Firebase Realtime Database');
                this.initialized = true;
                this.updateIndicator(true);
                return;
            } catch (err) {
                console.warn('[FirebaseSync] Firebase initialization error, falling back to local real-time polling bus:', err);
            }
        }

        // Zero-config fast polling fallback
        console.log('[FirebaseSync] Active on Local Event Bus (Fallback Mode)');
        this.updateIndicator(true, 'Local Realtime Bus');
        this.startFallbackPolling();
    },

    updateIndicator(connected, label = 'Firebase Real-Time') {
        const el = document.getElementById('live-status-badge');
        if (el) {
            el.innerHTML = `<span class="live-dot"></span> ${label}`;
            el.style.display = 'inline-flex';
        }
    },

    listenToFirebase() {
        if (!this.db) return;
        const eventsRef = this.db.ref('events').limitToLast(1);
        eventsRef.on('child_added', (snapshot) => {
            const data = snapshot.val();
            if (data && data.timestamp_ms > this.lastEventTimestamp) {
                this.lastEventTimestamp = data.timestamp_ms;
                this.dispatch(data.type, data.payload);
            }
        });
    },

    startFallbackPolling() {
        setInterval(async () => {
            try {
                // Poll local event bus endpoint
                const res = await fetch('scratch/firebase_events.json?_=' + Date.now());
                if (!res.ok) return;
                const events = await res.json();
                if (Array.isArray(events) && events.length > 0) {
                    const latest = events[0];
                    if (latest && latest.timestamp_ms > this.lastEventTimestamp) {
                        this.lastEventTimestamp = latest.timestamp_ms;
                        this.dispatch(latest.type, latest.payload);
                    }
                }
            } catch (e) {
                // Ignore transient fetch errors
            }
        }, 3000);
    },

    on(eventType, callback) {
        this.listeners.push({ type: eventType, callback });
    },

    dispatch(type, payload) {
        console.log(`[FirebaseSync Event] [${type}]`, payload);
        this.listeners
            .filter(l => l.type === '*' || l.type === type)
            .forEach(l => l.callback(type, payload));

        // Global UI Notification Toast
        this.showEventNotification(type, payload);
    },

    showEventNotification(type, payload) {
        let msg = '';
        let level = 'info';

        switch (type) {
            case 'NEW_SALE':
                msg = `New Sale ${payload.receipt_number} processed: $${payload.total_amount}`;
                level = 'success';
                break;
            case 'STOCK_UPDATE':
                msg = `Stock level updated: ${payload.action || 'Inventory modified'}`;
                level = 'info';
                break;
            case 'EXPIRY_ALERT':
                msg = `Expiry Alert: Markdown promotion applied to batch #${payload.batch_id}`;
                level = 'warning';
                break;
            case 'AUTO_PO':
                msg = `Auto-Purchase Orders generated: ${payload.count} new order(s) created!`;
                level = 'warning';
                break;
            case 'WASTAGE_LOG':
                msg = `Wastage logged: ${payload.quantity} units (${payload.reason_code}) - Loss: $${payload.cost_loss}`;
                level = 'danger';
                break;
        }

        if (msg && window.TC_App) {
            window.TC_App.toast(msg, level);
            // Auto refresh current view if relevant
            window.TC_App.refreshCurrentView();
        }
    }
};
