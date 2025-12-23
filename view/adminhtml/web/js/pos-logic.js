function adminOrder(config) {
    return {
        urls: config,
        currentStoreId: null,

        // Data State
        searchQuery: '', searchResults: [], cart: [], loading: false, placingOrder: false, grandTotal: 0, generatedLink: null,
        // UI State
        productModalOpen: false, selectedProduct: null, customerModalOpen: false, customerSearchQuery: '', customerResults: [],
        // Modal State
        confirmModal: { open: false, title: '', message: '', pendingId: null },
        pendingStoreId: null,
        // Customer Logic
        isNewCustomer: false, isEditMode: false, isCompany: false, bulstatValid: null,
        // Shipping
        selectedShippingMethod: '', availableShippingMethods: [], backupAddress: null,

        // Models
        customer: { id: null, firstname: 'Guest', lastname: 'Client', email: 'guest@pos.local', telephone: '' },
        company: { name: '', uic: '', vat: '' },
        address: { street: '', city: '', postcode: '' },

        // INIT
        initPOS() {
            console.log("🚀 POS Initializing...");
            let targetId = parseInt(this.urls.defaultStoreId);

            if (!this.urls.isStoreLocked) {
                let saved = localStorage.getItem('ferrous_pos_store_id');
                if (saved && this.urls.stores.find(s => s.id == saved)) {
                    targetId = parseInt(saved);
                }
            }
            this.currentStoreId = targetId;
            console.log("✅ Active Store:", this.currentStoreId);

            // Econt Hack: Залъгваме модула, че сме в стандартен order create
            if (!window.order) {
                window.order = {
                    setShippingMethod: function(code) { console.log('Econt Method Set:', code); },
                    loadShippingRates: function() { console.log('Econt Load Rates'); }
                };
            }

            this.updateShippingMethods();
        },

        getStoreName(id) {
            let store = this.urls.stores.find(s => s.id == id);
            return store ? (store.website + ' - ' + store.name) : 'Select Store';
        },

        // STORE SWITCHER
        requestStoreSwitch(newStoreId) {
            if (this.cart.length === 0 && !this.customer.id && !this.isNewCustomer) {
                this.currentStoreId = newStoreId;
                this.switchStoreInternal();
                return;
            }
            this.pendingStoreId = newStoreId;
            this.confirmModal.title = 'Change POS Location?';
            this.confirmModal.message = '⚠️ Switching Store will CLEAR all data. Proceed?';
            this.confirmModal.open = true;
        },

        closeConfirm(confirmed) {
            this.confirmModal.open = false;
            if (confirmed) {
                this.currentStoreId = this.pendingStoreId;
                this.switchStoreInternal();
            } else {
                this.pendingStoreId = null;
            }
        },

        switchStoreInternal() {
            localStorage.setItem('ferrous_pos_store_id', this.currentStoreId);
            this.loading = true;
            this.cart = []; this.grandTotal = 0; this.searchResults = []; this.searchQuery = '';
            this.resetCustomer();
            setTimeout(() => { this.updateShippingMethods(); this.loading = false; }, 100);
        },

        // SHIPPING
        updateShippingMethods() {
            let sid = this.currentStoreId;
            let methods = this.urls.allShippingMethods[sid] || this.urls.allShippingMethods[String(sid)] || [];
            this.availableShippingMethods = [...methods];
            this.selectedShippingMethod = (this.availableShippingMethods.length > 0) ? this.availableShippingMethods[0].code : '';
        },

        onShippingChange() {
            if (!this.selectedShippingMethod) return;
            let method = this.selectedShippingMethod.toLowerCase();
            let isPickup = method.includes('pickup') || method.includes('store');

            if (!isPickup) {
                if ((!this.address.city || !this.address.street) && this.backupAddress) {
                    this.address = { ...this.backupAddress };
                }
            }
        },

        // GETTERS
        get isValidOrder() {
            if (this.cart.length === 0) return false;
            if (!this.customer.email || !this.customer.firstname) return false;
            if (this.isCompany && (!this.company.name || !this.company.uic)) return false;
            if (!this.selectedShippingMethod) return false;

            let method = this.selectedShippingMethod.toLowerCase();
            let isPickup = method.includes('pickup') || method.includes('store');
            if (!isPickup && !this.address.city) return false;
            return true;
        },

        // API CALLS
        searchProducts() {
            if (this.searchQuery.length < 2) return;
            this.loading = true;
            let url = this.urls.searchUrl + '?isAjax=1&form_key=' + this.urls.formKey + '&q=' + encodeURIComponent(this.searchQuery) + '&store_id=' + this.currentStoreId;
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json()).then(d => { this.searchResults = Array.isArray(d) ? d : []; this.loading = false; })
                .catch(() => this.loading = false);
        },
        searchCustomers() {
            if (this.customerSearchQuery.length < 2) return;
            let url = this.urls.customerSearchUrl + '?isAjax=1&form_key=' + this.urls.formKey + '&q=' + encodeURIComponent(this.customerSearchQuery) + '&store_id=' + this.currentStoreId;
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json()).then(d => { this.customerResults = Array.isArray(d) ? d : []; })
                .catch(e => console.error(e));
        },

        // CART ACTIONS
        openProductModal(product) { this.selectedProduct = product; this.productModalOpen = true; },
        addToCart(product) {
            let ex = this.cart.find(i => i.sku === product.sku);
            if (ex) ex.qty++; else this.cart.push({ ...product, qty: 1 });
            this.searchQuery = ''; this.searchResults = []; this.calculateTotal();
        },
        removeFromCart(i) { this.cart.splice(i, 1); this.calculateTotal(); },
        calculateTotal() { this.grandTotal = this.cart.reduce((s, i) => s + (i.price * i.qty), 0); },
        formatPrice(p) { return parseFloat(p).toFixed(2) + ' BGN'; },

        // CUSTOMER ACTIONS
        openCustomerModal() { this.customerModalOpen = true; this.customerSearchQuery = ''; },
        selectCustomer(c) {
            this.customer = { id: c.id, firstname: c.firstname, lastname: c.lastname, email: c.email, telephone: c.telephone || '' };
            this.address = c.city ? { city: c.city, street: c.street || '', postcode: c.postcode || '' } : { street: '', city: '', postcode: '' };
            this.backupAddress = { ...this.address };

            if(c.company) { this.isCompany = true; this.company = { name: c.company, uic: c.vat_id || '', vat: '' }; }
            else { this.isCompany = false; this.company = { name: '', uic: '', vat: '' }; }
            this.customerModalOpen = false; this.customerResults = [];
        },
        startNewCustomer() { this.isNewCustomer = true; this.isEditMode = false; this.resetCustomerData(); },
        resetCustomer() { this.resetCustomerData(); this.customer.id = null; this.isNewCustomer = false; this.isEditMode = false; },
        resetCustomerData() {
            this.customer = { id: null, firstname: '', lastname: '', email: '', telephone: '' };
            this.company = { name: '', uic: '', vat: '' };
            this.isCompany = false; this.address = { street: '', city: '', postcode: '' }; this.backupAddress = null;
        },
        validateBulstat() { let uic = this.company.uic; if(/^\d{9,13}$/.test(uic)) { this.bulstatValid = true; if(!this.company.vat) this.company.vat = 'BG' + uic; } else this.bulstatValid = false; },

        // ORDER ACTIONS
        placeOrder() {
            if (!confirm('Place Order?')) return;
            this.placingOrder = true;
            const payload = {
                store_id: this.currentStoreId,
                customer: this.customer, is_new_customer: this.isNewCustomer, is_edit_mode: this.isEditMode,
                is_company: this.isCompany, company_data: this.isCompany ? this.company : null,
                shipping_method: this.selectedShippingMethod, address: this.address,
                items: this.cart.map(i => ({ id: i.id, qty: i.qty })), form_key: this.urls.formKey
            };
            fetch(this.urls.createUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(payload) })
                .then(r => r.json()).then(d => {
                this.placingOrder = false;
                if(d.success) { alert('✅ Order #' + d.order_increment_id + ' Created!'); this.cart = []; this.grandTotal = 0; this.resetCustomer(); }
                else { alert('❌ ' + d.message); }
            }).catch(() => { this.placingOrder = false; alert('Server Error'); });
        },
        generatePaymentLink() { this.loading = true; const payload = { store_id: this.currentStoreId, items: this.cart.map(i => ({ id: i.id, qty: i.qty })), form_key: this.urls.formKey }; fetch(this.urls.shareUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(payload) }).then(r => r.json()).then(d => { this.loading = false; if(d.success) { this.generatedLink = d.link; } else { alert('Error: ' + d.message); } }).catch(e => { this.loading = false; alert('Server Error'); }); },
        copyLink() { let input = document.getElementById("shareLinkInput"); if(input) { input.select(); document.execCommand("copy"); alert("Link copied!"); } },
        shareCart() { this.generatePaymentLink(); }
    }
}