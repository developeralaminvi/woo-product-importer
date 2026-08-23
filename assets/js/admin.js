/**
 * Product Importer — admin UI.
 * Requires jQuery (bundled with WordPress).
 *
 * Two modes:
 *   category — paste one category/collection link -> preview its products -> import
 *   site     — paste the store homepage      -> discover all categories (with images)
 *               -> import every selected category's products automatically
 */
(function ($) {
    'use strict';

    var BATCH_SIZE = 4; // Import 3-4 products per AJAX batch to prevent server hangs

    var state = {
        mode: 'category',
        url: '',
        origin: '',
        platform: '',
        products: [],
        categories: [],
        fetching: false,
        stop: false,
        stats: { imported: 0, skipped: 0, linked: 0, failed: 0 }
    };

    /* ------------------------------------------------ small helpers */

    function uid() {
        return 'p' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
    }

    function esc(v) {
        if (v === null || v === undefined) { return ''; }
        return String(v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function escUrl(url) {
        if (!url) { return ''; }
        var s = String(url).trim();
        if (!/^https?:\/\//i.test(s) && !/^\/(?!\/)/.test(s)) {
            return '';
        }
        return esc(s);
    }

    function extractOrigin(url) {
        var m = url.match(/^(https?:\/\/[^/]+)/i);
        return m ? m[1] : url;
    }

    function getProduct(id) {
        for (var i = 0; i < state.products.length; i++) {
            if (state.products[i]._id === id) { return state.products[i]; }
        }
        return null;
    }

    function setBusy(busy) {
        state.fetching = busy;
        $('#wpi-fetch, #wpi-import-selected, #wpi-import-all, #wpi-import-site, #wpi-cat-select-all, #wpi-cat-select-none')
            .prop('disabled', busy);
        $('#wpi-stop').toggle(busy);
    }

    function showProgress(percent, label) {
        $('#wpi-progress-wrap').show();
        $('#wpi-progress-bar').css('width', percent + '%');
        $('#wpi-progress-label').text(label || '');
    }

    function hideProgress() {
        $('#wpi-progress-wrap').hide();
    }

    function showSummary(text, type) {
        $('#wpi-summary')
            .attr('class', 'notice ' + (type || 'notice-success'))
            .html('<p>' + text + '</p>')
            .show();
    }

    function setRowState(id, cls, html) {
        $('#wpi-tbody .wpi-state[data-id="' + id + '"]')
            .attr('class', 'wpi-state ' + cls)
            .html(html);
    }

    function setCatState(id, cls, html) {
        $('#wpi-cat-tbody .wpi-state[data-id="' + id + '"]')
            .attr('class', 'wpi-state ' + cls)
            .html(html);
    }

    function platformLabel(p) {
        if (p === 'shopify') { return 'Shopify'; }
        if (p === 'woocommerce') { return 'WooCommerce'; }
        return p ? esc(p) : 'HTML';
    }

    /* ------------------------------------------------------------ mode */

    $('input[name="wpi-mode"]').on('change', function () {
        state.mode = this.value;
        $('#wpi-url').attr('placeholder', state.mode === 'site'
            ? 'https://store.com  — পুরো সাইট (সব ক্যাটাগরি + সব প্রোডাক্ট)'
            : 'https://store.com/collections/men   or   https://store.com/product-category/men');
        $('#wpi-note').html(state.mode === 'site'
            ? 'পুরো সাইট মোড: শুধু স্টোরের মূল লিংক দিন (যেমন <code>https://store.com</code>) — সব ক্যাটাগরি, ক্যাটাগরি ছবি আর সব প্রোডাক্ট অটো ইমপোর্ট হবে।'
            : 'ক্যাটাগরি মোড: <code>…/collections/…</code> (Shopify) বা <code>…/product-category/…</code>, <code>…/shop/…</code> (WooCommerce)।');
        $('#wpi-single-options').toggle(state.mode === 'category');
        $('#wpi-site-options').toggle(state.mode === 'site');
        $('#wpi-cat-preview, #wpi-results, #wpi-empty, #wpi-summary').hide();
    });

    /* ------------------------------------------------------------ load */

    $('#wpi-fetch').on('click', function () {
        var url = $('#wpi-url').val().trim();
        if (!url) { alert('লিংকটা পেস্ট করুন — e.g. https://store.com/collections/men'); return; }
        if (!/^https?:\/\//i.test(url)) { alert('লিংকটা http:// বা https:// দিয়ে শুরু হওয়া দরকার।'); return; }

        state.url = url;
        state.origin = extractOrigin(url);
        state.stop = false;
        state.stats = { imported: 0, skipped: 0, linked: 0, failed: 0 };
        $('#wpi-cat-preview, #wpi-results, #wpi-empty, #wpi-summary').hide();
        $('#wpi-count').text('0');
        setBusy(true);

        if (state.mode === 'site') {
            loadSiteCategories();
        } else {
            state.products = [];
            fetchPage(1);
        }
    });

    $('#wpi-stop').on('click', function () {
        state.stop = true;
        $('#wpi-stop').prop('disabled', true);
    });

    /* ==================================================================
     * MODE: category link
     * ================================================================ */

    function fetchPage(page) {
        if (state.stop) { finishFetch(); return; }
        showProgress(Math.min(95, page * 5), 'Fetching page ' + page + '…');
        $('#wpi-busy-label').text('Fetching page ' + page + '…');

        $.post(wpi_admin.ajax_url, {
            action: 'wpi_fetch_products',
            nonce: wpi_admin.nonce,
            url: state.url,
            page: page,
            deep: $('#wpi-deep').is(':checked') ? 1 : 0
        }).done(function (res) {
            if (!res || !res.success) {
                var msg = (res && res.data && res.data.message) ? res.data.message : 'Fetch failed. Check the link and try again.';
                showSummary('<strong>Error:</strong> ' + esc(msg), 'notice-error');
                finishFetch();
                return;
            }

            var d = res.data;
            state.platform = d.platform || '';
            (d.products || []).forEach(function (p) {
                p._id = uid();
                p._checked = true;
                p._state = 'new';
                state.products.push(p);
            });
            $('#wpi-count').text(state.products.length);

            var max = parseInt(d.max_page, 10) || 0;
            if (max > page) { fetchPage(page + 1); return; }
            if (d.has_more && page < 60) { fetchPage(page + 1); return; }
            finishFetch();
        }).fail(function () {
            showSummary('<strong>Network error</strong> — could not reach the site. Try again.', 'notice-error');
            finishFetch();
        });
    }

    function finishFetch() {
        setBusy(false);
        hideProgress();
        $('#wpi-stop').hide().prop('disabled', false);
        $('#wpi-busy-label').text('');

        if (!state.products.length) {
            $('#wpi-empty').show();
            $('#wpi-results').hide();
            return;
        }

        renderTable();
        $('#wpi-results').show();
        showSummary(
            'Found <strong>' + state.products.length + '</strong> product(s) in this category from a <strong>' +
            platformLabel(state.platform) + '</strong> store. Only products of the pasted category are listed.' +
            ' Tick the ones you want and click <strong>Import</strong>.'
        );
    }

    function renderTable() {
        var html = '';
        state.products.forEach(function (p) {
            var safeImg = escUrl(p.image);
            var img = safeImg
                ? '<img src="' + safeImg + '" alt="" loading="lazy">'
                : '<span class="wpi-noimg">No image</span>';
            var safeLink = escUrl(p.source_url);
            var link = safeLink
                ? '<a href="' + safeLink + '" target="_blank" rel="noopener noreferrer">' + esc(p.title) + '</a>'
                : esc(p.title);
            var extra = [];
            if (p.vendor) { extra.push(esc(p.vendor)); }
            if (p.product_type) { extra.push(esc(p.product_type)); }
            var sku = p.sku ? esc(p.sku) : '—';
            var price = (p.price !== '' && p.price !== null && p.price !== undefined) ? esc(p.price) : '—';

            html += '<tr data-id="' + p._id + '">' +
                '<td><input type="checkbox" class="wpi-check" ' + (p._checked ? 'checked' : '') + '></td>' +
                '<td class="wpi-thumb">' + img + '</td>' +
                '<td class="wpi-title-cell">' + link +
                    (extra.length ? '<br><small>' + extra.join(' · ') + '</small>' : '') + '</td>' +
                '<td>' + price + '</td>' +
                '<td>' + sku + '</td>' +
                '<td><span class="wpi-state" data-id="' + p._id + '">Ready</span></td>' +
                '</tr>';
        });
        $('#wpi-tbody').html(html);
    }

    $('#wpi-tbody').on('change', '.wpi-check', function () {
        var p = getProduct($(this).closest('tr').data('id'));
        if (p) { p._checked = this.checked; }
    });

    $('#wpi-select-all').on('click', function () {
        state.products.forEach(function (p) { if (p._state === 'new') { p._checked = true; } });
        syncChecks();
    });

    $('#wpi-select-none').on('click', function () {
        state.products.forEach(function (p) { p._checked = false; });
        syncChecks();
    });

    function syncChecks() {
        $('#wpi-tbody .wpi-check').each(function () {
            var p = getProduct($(this).closest('tr').data('id'));
            $(this).prop('checked', !!(p && p._checked));
        });
    }

    function selectedForImport() {
        var out = [];
        state.products.forEach(function (p) {
            if (p._checked && p._state === 'new') { out.push(p); }
        });
        return out;
    }

    $('#wpi-import-selected').on('click', function () { runImport(selectedForImport()); });

    $('#wpi-import-all').on('click', function () {
        var all = [];
        state.products.forEach(function (p) { if (p._state === 'new') { all.push(p); } });
        runImport(all);
    });

    function runImport(list) {
        if (!list.length) { alert('No products selected.'); return; }
        if (!confirm('Import ' + list.length + ' product(s) into your WooCommerce store?')) { return; }

        state.stop = false;
        state.stats = { imported: 0, skipped: 0, linked: 0, failed: 0 };
        $('#wpi-summary').hide();
        setBusy(true);
        $('#wpi-stop').show().prop('disabled', false);

        var status = $('#wpi-status').val() === 'publish' ? 'publish' : 'draft';
        var skipDup = $('#wpi-skip-dup').is(':checked') ? 1 : 0;
        var rawCats = $('#wpi-cats').val();
        var cats = (rawCats || [])
            .map(function (v) { return parseInt(v, 10); })
            .filter(function (n) { return !isNaN(n) && n > 0; })
            .join(',');

        runNext(list, 0, list.length, status, skipDup, cats);
    }

    function runNext(list, index, total, status, skipDup, cats) {
        if (state.stop || index >= total) { finishImport(); return; }

        var batch = list.slice(index, index + BATCH_SIZE);
        var pEnd = Math.min(index + batch.length, total);
        var percent = total > 0 ? Math.round((index / total) * 100) : 100;
        showProgress(percent, 'Importing ' + (index + 1) + '-' + pEnd + ' of ' + total + ' products…');
        $('#wpi-busy-label').text('Importing ' + (index + 1) + '-' + pEnd + ' of ' + total + '…');

        batch.forEach(function (p) {
            setRowState(p._id, 'importing', 'Importing…');
        });

        $.ajax({
            url: wpi_admin.ajax_url,
            method: 'POST',
            data: {
                action: 'wpi_import_products_batch',
                nonce: wpi_admin.nonce,
                products: JSON.stringify(batch),
                status: status,
                skip_dup: skipDup,
                categories: cats,
                origin: state.origin
            }
        }).done(function (res) {
            if (res && res.success && res.data && res.data.results) {
                var results = res.data.results;
                results.forEach(function (r) {
                    var p = getProduct(r._id);
                    if (!p) { return; }
                    if (r.ok) {
                        p._state = 'imported';
                        if (r.linked) {
                            state.stats.linked++;
                            setRowState(p._id, 'imported', 'Linked ✓');
                        } else {
                            state.stats.imported++;
                            var safeEdit = escUrl(r.edit_url);
                            var edit = safeEdit ? ' <a href="' + safeEdit + '" target="_blank">edit</a>' : '';
                            setRowState(p._id, 'imported', 'Imported ✓' + edit);
                        }
                    } else if (r.skipped) {
                        p._state = 'skipped';
                        state.stats.skipped++;
                        setRowState(p._id, 'skipped', 'Skipped — ' + esc(r.message || 'duplicate'));
                    } else {
                        p._state = 'failed';
                        state.stats.failed++;
                        setRowState(p._id, 'failed', 'Failed — ' + esc(r.message || ''));
                    }
                });
            } else {
                batch.forEach(function (p) {
                    p._state = 'failed';
                    state.stats.failed++;
                    setRowState(p._id, 'failed', 'Failed (server)');
                });
            }
            next();
        }).fail(function () {
            batch.forEach(function (p) {
                p._state = 'failed';
                state.stats.failed++;
                setRowState(p._id, 'failed', 'Network error');
            });
            next();
        });

        function next() {
            var ni = index + batch.length;
            if (ni >= total) { finishImport(); }
            else { runNext(list, ni, total, status, skipDup, cats); }
        }
    }

    function finishImport() {
        setBusy(false);
        hideProgress();
        $('#wpi-stop').hide().prop('disabled', false);
        $('#wpi-busy-label').text('');

        var s = state.stats;
        var msg = 'Import finished. <strong>Imported:</strong> ' + s.imported +
            (s.linked ? ' &nbsp;|&nbsp; <strong>Linked (multi-category):</strong> ' + s.linked : '') +
            ' &nbsp;|&nbsp; <strong>Skipped (duplicate):</strong> ' + s.skipped +
            ' &nbsp;|&nbsp; <strong>Failed:</strong> ' + s.failed +
            (state.mode === 'site' ? ' — ক্যাটাগরি অটো তৈরি হয়েছে (ছবি সহ, যেগুলোতে ছিল)।' : '') +
            ' Go to <strong>Products</strong> in the WooCommerce menu to review them.';
        showSummary(msg, s.failed ? 'notice-warning' : 'notice-success');

        $('#wpi-tbody .wpi-check:checked').prop('checked', false);
    }

    /* ==================================================================
     * MODE: whole site
     * ================================================================ */

    function loadSiteCategories() {
        showProgress(8, 'Scanning the site for categories…');
        $('#wpi-busy-label').text('Scanning the site for categories…');

        $.post(wpi_admin.ajax_url, {
            action: 'wpi_fetch_site_categories',
            nonce: wpi_admin.nonce,
            url: state.url
        }).done(function (res) {
            if (!res || !res.success) {
                var msg = (res && res.data && res.data.message) ? res.data.message : 'Could not find any category.';
                showSummary('<strong>Error:</strong> ' + esc(msg), 'notice-error');
                setBusy(false);
                hideProgress();
                $('#wpi-busy-label').text('');
                return;
            }

            var d = res.data;
            state.platform = d.platform || '';
            state.categories = (d.categories || []).map(function (c) {
                c._id = uid();
                c._checked = true;
                c._state = 'new';
                c.platform = d.platform;
                return c;
            });

            renderCategoryTable();
            $('#wpi-cat-preview').show();
            setBusy(false);
            hideProgress();
            $('#wpi-busy-label').text('');
            showSummary(
                'Found <strong>' + state.categories.length + '</strong> category/collection on a <strong>' +
                platformLabel(state.platform) + '</strong> store. Select the categories and click <strong>Import all products</strong> — ক্যাটাগরি (ছবি সহ) ও সব প্রোডাক্ট অটো ইমপোর্ট হবে।'
            );
        }).fail(function () {
            showSummary('<strong>Network error</strong> — could not reach the site.', 'notice-error');
            setBusy(false);
            hideProgress();
            $('#wpi-busy-label').text('');
        });
    }

    function visibleCategories() {
        var anyCounts = state.categories.some(function (c) { return (parseInt(c.count, 10) || 0) > 0; });
        var hide = $('#wpi-hide-empty').is(':checked') && anyCounts;
        return state.categories.filter(function (c) {
            return !hide || (parseInt(c.count, 10) || 0) > 0;
        });
    }

    function renderCategoryTable() {
        var list = visibleCategories();
        var html = '';
        list.forEach(function (c) {
            var safeImg = escUrl(c.image);
            var img = safeImg
                ? '<img src="' + safeImg + '" alt="" loading="lazy">'
                : '<span class="wpi-noimg">No image</span>';
            var count = c.count ? esc(c.count) : '—';
            var extra = c.url ? '<br><small>' + esc(c.url) + '</small>' : '';
            html += '<tr data-id="' + c._id + '">' +
                '<td><input type="checkbox" class="wpi-cat-check" ' + (c._checked ? 'checked' : '') + '></td>' +
                '<td class="wpi-thumb">' + img + '</td>' +
                '<td class="wpi-title-cell">' + esc(c.title) + extra + '</td>' +
                '<td>' + count + '</td>' +
                '<td><span class="wpi-state" data-id="' + c._id + '">Ready</span></td>' +
                '</tr>';
        });
        $('#wpi-cat-tbody').html(html);
        $('#wpi-cat-count').text(list.length);
    }

    $('#wpi-hide-empty').on('change', function () {
        renderCategoryTable();
    });

    $('#wpi-cat-tbody').on('change', '.wpi-cat-check', function () {
        var id = $(this).closest('tr').data('id');
        state.categories.forEach(function (c) { if (c._id === id) { c._checked = this.checked; } }.bind(this));
    });

    $('#wpi-cat-select-all').on('click', function () {
        visibleCategories().forEach(function (c) { if (c._state === 'new') { c._checked = true; } });
        syncCatChecks();
    });

    $('#wpi-cat-select-none').on('click', function () {
        visibleCategories().forEach(function (c) { c._checked = false; });
        syncCatChecks();
    });

    function syncCatChecks() {
        $('#wpi-cat-tbody .wpi-cat-check').each(function () {
            var id = $(this).closest('tr').data('id');
            var found = false;
            state.categories.forEach(function (c) { if (c._id === id) { found = c._checked; } });
            $(this).prop('checked', found);
        });
    }

    $('#wpi-import-site').on('click', function () {
        var selected = visibleCategories().filter(function (c) { return c._checked && c._state === 'new'; });
        if (!selected.length) { alert('কমপক্ষে একটা ক্যাটাগরি বেছে নিন।'); return; }

        var totalProducts = selected.reduce(function (sum, c) { return sum + (parseInt(c.count, 10) || 0); }, 0);
        if (!confirm('Import all products from ' + selected.length + ' category(ies)' +
            (totalProducts ? ' (approx ' + totalProducts + ' products)' : '') +
            '? Categories will be created automatically — with images when available — and every product imported.')) { return; }

        state.stop = false;
        state.stats = { imported: 0, skipped: 0, linked: 0, failed: 0 };
        $('#wpi-summary').hide();
        setBusy(true);
        $('#wpi-stop').show().prop('disabled', false);

        var status = $('#wpi-status-site').val() === 'publish' ? 'publish' : 'draft';
        var skipDup = $('#wpi-skip-dup-site').is(':checked') ? 1 : 0;

        importSiteNext(selected, 0, status, skipDup);
    });

    function importSiteNext(cats, i, status, skipDup) {
        if (state.stop || i >= cats.length) { finishImport(); return; }

        var c = cats[i];
        var percent = Math.round((i / cats.length) * 100);
        showProgress(percent, 'Category ' + (i + 1) + '/' + cats.length + ': ' + c.title + ' — loading products…');
        $('#wpi-busy-label').text('Preparing: ' + c.title);
        setCatState(c._id, 'importing', 'Preparing category…');

        $.post(wpi_admin.ajax_url, {
            action: 'wpi_prepare_site_category',
            nonce: wpi_admin.nonce,
            origin: state.origin,
            category: JSON.stringify(c)
        }).done(function (res) {
            if (res && res.success && res.data && res.data.ok) {
                var termId = res.data.term_id;
                var products = res.data.products || [];
                if (!products.length) {
                    c._state = 'imported';
                    setCatState(c._id, 'imported', '✓ No products found');
                    nextCat();
                    return;
                }

                products.forEach(function (p) {
                    if (!p._id) { p._id = uid(); }
                });

                var catStats = { imported: 0, skipped: 0, linked: 0, failed: 0 };
                importCategoryProductBatch(c, termId, products, 0, catStats, status, skipDup, function () {
                    c._state = 'imported';
                    setCatState(c._id, 'imported',
                        '✓ Imported ' + catStats.imported +
                        (catStats.linked ? ' · linked ' + catStats.linked : '') +
                        (catStats.skipped ? ' · skipped ' + catStats.skipped : '') +
                        (catStats.failed ? ' · failed ' + catStats.failed : ''));
                    nextCat();
                });
            } else {
                c._state = 'failed';
                state.stats.failed++;
                var msg = (res && res.data && res.data.message) ? res.data.message : 'server error';
                setCatState(c._id, 'failed', 'Failed — ' + esc(msg));
                nextCat();
            }
        }).fail(function () {
            c._state = 'failed';
            state.stats.failed++;
            setCatState(c._id, 'failed', 'Network error');
            nextCat();
        });

        function nextCat() {
            var ni = i + 1;
            if (ni >= cats.length) { finishImport(); }
            else { importSiteNext(cats, ni, status, skipDup); }
        }
    }

    function importCategoryProductBatch(cat, termId, products, pIndex, catStats, status, skipDup, onComplete) {
        if (state.stop || pIndex >= products.length) {
            onComplete();
            return;
        }

        var batch = products.slice(pIndex, pIndex + BATCH_SIZE);
        var pTotal = products.length;
        var pEnd = Math.min(pIndex + batch.length, pTotal);

        setCatState(cat._id, 'importing', 'Importing ' + (pIndex + 1) + '-' + pEnd + ' of ' + pTotal + '…');
        $('#wpi-busy-label').text(cat.title + ': ' + (pIndex + 1) + '-' + pEnd + '/' + pTotal);

        $.ajax({
            url: wpi_admin.ajax_url,
            method: 'POST',
            data: {
                action: 'wpi_import_products_batch',
                nonce: wpi_admin.nonce,
                products: JSON.stringify(batch),
                status: status,
                skip_dup: skipDup,
                categories: termId,
                origin: state.origin
            }
        }).done(function (res) {
            if (res && res.success && res.data && res.data.results) {
                res.data.results.forEach(function (r) {
                    if (r.ok) {
                        if (r.linked) {
                            catStats.linked++;
                            state.stats.linked++;
                        } else {
                            catStats.imported++;
                            state.stats.imported++;
                        }
                    } else if (r.skipped) {
                        catStats.skipped++;
                        state.stats.skipped++;
                    } else {
                        catStats.failed++;
                        state.stats.failed++;
                    }
                });
            } else {
                batch.forEach(function () {
                    catStats.failed++;
                    state.stats.failed++;
                });
            }
            nextBatch();
        }).fail(function () {
            batch.forEach(function () {
                catStats.failed++;
                state.stats.failed++;
            });
            nextBatch();
        });

        function nextBatch() {
            var nextIndex = pIndex + batch.length;
            if (nextIndex >= products.length) {
                onComplete();
            } else {
                importCategoryProductBatch(cat, termId, products, nextIndex, catStats, status, skipDup, onComplete);
            }
        }
    }

})(jQuery);
