// =====================================================================
// TC LIMITED - Barcode & SKU Scanning Controller
// =====================================================================

const BarcodeScanner = {
    buffer: '',
    lastKeyTime: 0,
    callback: null,

    init() {
        console.log('[BarcodeScanner] Hardware & software scanner listener activated.');
        window.addEventListener('keydown', (e) => this.handleKeypress(e));
    },

    // Listen to physical USB barcode scanner keystrokes
    handleKeypress(e) {
        // If user is currently typing in a normal text input or textarea, don't hijack unless it's a dedicated scanner field
        const activeTag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
        const isScanningInput = document.activeElement && document.activeElement.classList.contains('scanner-input');
        
        if (activeTag === 'textarea' || (activeTag === 'input' && !isScanningInput)) {
            return;
        }

        const currentTime = Date.now();
        const diff = currentTime - this.lastKeyTime;
        this.lastKeyTime = currentTime;

        // Scanners usually type very fast (< 50ms per key)
        if (e.key === 'Enter') {
            if (this.buffer.length >= 4) {
                console.log('[BarcodeScanner] Scanned Barcode:', this.buffer);
                this.triggerScan(this.buffer.trim());
                this.buffer = '';
                e.preventDefault();
            }
            this.buffer = '';
            return;
        }

        if (e.key.length === 1) {
            // If delay between keys is long, reset buffer
            if (diff > 250) {
                this.buffer = '';
            }
            this.buffer += e.key;
        }
    },

    setHandler(callback) {
        this.callback = callback;
    },

    triggerScan(barcode) {
        if (this.callback) {
            this.callback(barcode);
        } else if (window.TC_POS && window.TC_App.currentView === 'pos') {
            window.TC_POS.handleScan(barcode);
        } else if (window.TC_App) {
            window.TC_App.handleGlobalBarcodeScan(barcode);
        }
    },

    // Open Quick Scan Simulation Modal
    openSimModal() {
        const modal = document.getElementById('modal-scanner-sim');
        if (modal) modal.classList.add('active');
        const input = document.getElementById('sim-barcode-input');
        if (input) {
            input.value = '';
            input.focus();
        }
    },

    closeSimModal() {
        const modal = document.getElementById('modal-scanner-sim');
        if (modal) modal.classList.remove('active');
    },

    submitSimScan() {
        const input = document.getElementById('sim-barcode-input');
        if (input && input.value.trim()) {
            const code = input.value.trim();
            this.triggerScan(code);
            this.closeSimModal();
        }
    }
};

window.BarcodeScanner = BarcodeScanner;
document.addEventListener('DOMContentLoaded', () => BarcodeScanner.init());
