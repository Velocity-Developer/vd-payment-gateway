/**
 * Dashboard VD Payment Gateway: komponen Alpine.js (simpan tanpa muat ulang lewat REST vd-pg/v1/admin).
 * Dimuat sebelum alpine.min.js; komponen didaftarkan pada event alpine:init.
 * Tanpa JS, formulir tetap terkirim biasa ke admin-post.php. Data ditampilkan hanya lewat x-text (tanpa x-html).
 */
(function () {
    'use strict';

    var cfg = window.vdpgAdmin || { restUrl: '', nonce: '', i18n: {} };
    var i18n = cfg.i18n || {};

    function format(text) {
        var args = Array.prototype.slice.call(arguments, 1);
        var i = 0;
        return String(text || '').replace(/%(?:(\d+)\$)?d/g, function (m, pos) {
            var value = pos ? args[pos - 1] : args[i++];
            return String(value);
        });
    }

    function api(path, options) {
        options = options || {};
        var headers = { 'X-WP-Nonce': cfg.nonce, Accept: 'application/json' };
        if (options.body !== undefined) {
            headers['Content-Type'] = 'application/json';
        }
        return fetch(cfg.restUrl + path, {
            method: options.method || 'GET',
            credentials: 'same-origin',
            headers: headers,
            body: options.body !== undefined ? JSON.stringify(options.body) : undefined
        }).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) {
                if (!res.ok) {
                    // Nonce wp_rest kedaluwarsa (tab terbuka lama): minta muat ulang, bukan galat umum.
                    if (data && data.code === 'rest_cookie_invalid_nonce') {
                        throw new Error(i18n.expired);
                    }
                    throw new Error((data && data.message) || i18n.error);
                }
                return data;
            });
        });
    }

    function notify(text, type) {
        var store = window.Alpine.store('vdpgNotice');
        store.text = String(text || '');
        store.type = type === 'error' ? 'error' : 'success';
    }

    function fail(err) {
        notify(err && err.message ? err.message : i18n.error, 'error');
    }

    document.addEventListener('alpine:init', function () {
        var Alpine = window.Alpine;
        Alpine.store('vdpgNotice', { text: '', type: 'success' });

        /** Formulir pengaturan satu gateway. */
        Alpine.data('vdpgSettings', function (init) {
            return {
                i18n: i18n,
                gateway: init.gateway,
                active: !!init.active,
                mode: init.mode || '',
                busy: false,
                save: function (form) {
                    var self = this;
                    var settings = {};
                    Array.prototype.forEach.call(form.elements, function (el) {
                        var m = /^vdpg\[([a-z0-9_]+)\]$/.exec(el.name || '');
                        if (!m) { return; }
                        settings[m[1]] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
                    });
                    self.busy = true;
                    notify('');
                    api('settings/' + encodeURIComponent(self.gateway), { method: 'POST', body: { settings: settings } })
                        .then(function (data) {
                            self.active = !!data.active;
                            self.mode = data.mode || '';
                            // Secret tidak pernah dikirim balik: kosongkan isian, tandai tersimpan.
                            form.querySelectorAll('[data-vdpg-secret]').forEach(function (el) {
                                var key = (/^vdpg\[([a-z0-9_]+)\]$/.exec(el.name) || [])[1];
                                el.value = '';
                                el.placeholder = data.secrets && data.secrets[key] ? i18n.secretSaved : '';
                            });
                            var dot = document.querySelector('[data-vdpg-dot="' + self.gateway + '"]');
                            if (dot) { dot.classList.toggle('is-on', self.active); }
                            notify(data.message || i18n.saved);
                        })
                        .catch(fail)
                        .then(function () { self.busy = false; });
                }
            };
        });

        /** Tab Log: simpan sakelar dan hapus log. */
        Alpine.data('vdpgLog', function (init) {
            return {
                i18n: i18n,
                enabled: !!init.enabled,
                saved: !!init.enabled,
                busy: false,
                count: null,
                countText: function () { return format(i18n.lines, this.count); },
                apply: function (data) {
                    this.enabled = this.saved = !!data.enabled;
                    this.count = data.count;
                    this.$refs.log.value = (data.lines || []).join('\n');
                    notify(data.message);
                },
                submit: function (event) {
                    var self = this;
                    var clear = event.submitter && event.submitter.name === 'clear';
                    // onclick confirm() pada tombol hapus sudah dijalankan sebelum submit.
                    self.busy = true;
                    notify('');
                    var req = clear ? api('log', { method: 'DELETE' }) : api('log', { method: 'POST', body: { enabled: !!self.enabled } });
                    req.then(function (data) { self.apply(data); })
                        .catch(fail)
                        .then(function () { self.busy = false; });
                }
            };
        });

        /** Tab Transaksi: filter langsung, halaman, cek status, kartu statistik. */
        Alpine.data('vdpgTransactions', function (init) {
            return {
                filters: {
                    search: init.filters.search || '',
                    status: init.filters.status || '',
                    gateway: init.filters.gateway || ''
                },
                rows: [],
                stats: null,
                page: init.page || 1,
                pages: 1,
                total: 0,
                ready: false,
                busy: false,
                syncing: 0,
                seq: 0,
                init: function () { this.load(this.page); },
                pageText: function () { return format(i18n.page, this.page, this.pages, this.total); },
                load: function (page) {
                    var self = this;
                    var seq = ++self.seq;
                    var q = new URLSearchParams({
                        page: String(page || 1),
                        search: self.filters.search,
                        status: self.filters.status,
                        gateway: self.filters.gateway
                    });
                    self.busy = true;
                    api('transactions?' + q.toString())
                        .then(function (data) {
                            if (seq !== self.seq) { return; } // jawaban lama (filter sudah berubah)
                            self.rows = data.rows || [];
                            self.stats = data.stats || null;
                            self.page = data.page;
                            self.pages = data.pages;
                            self.total = data.total;
                            self.ready = true;
                        })
                        .catch(fail)
                        .then(function () { if (seq === self.seq) { self.busy = false; } });
                },
                sync: function (row) {
                    var self = this;
                    self.syncing = row.id;
                    notify('');
                    api('transactions/' + row.id + '/sync', { method: 'POST', body: {} })
                        .then(function (data) {
                            var i = self.rows.findIndex(function (r) { return r.id === row.id; });
                            if (i !== -1 && data.row) { self.rows.splice(i, 1, data.row); }
                            if (data.stats) { self.stats = data.stats; }
                            notify(data.message);
                        })
                        .catch(fail)
                        .then(function () { self.syncing = 0; });
                }
            };
        });
    });
})();
