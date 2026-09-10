/**
 * Cybermaps — Shortcode Builder JS
 *
 * Live preview for the [cybermap] shortcode builder.
 * Uses event delegation on the builder container.
 */
(function() {
    var __ = wp.i18n.__;
    var sprintf = wp.i18n.sprintf;

    function initBuilder() {
        var container = document.getElementById('cybermaps-shortcode-builder');
        if (!container) return;

        var preview = container.querySelector('#cm-shortcode-preview');
        var desc = container.querySelector('#cm-shortcode-desc');
        var copyBtn = container.querySelector('.cm-copy-btn');
		var copyStatus = container.querySelector('#cm-shortcode-copy-status');
		var excludeError = container.querySelector('#cm-sc-exclude-error');

        if (!preview || !desc) return;

        function boundedInteger(input, fallback) {
            if (!input) return fallback;
            var minimum = parseInt(input.dataset.min, 10);
            var maximum = parseInt(input.dataset.max, 10);
            var parsed = parseInt(input.value, 10);
            if (!Number.isFinite(parsed)) return fallback;
            if (Number.isFinite(minimum)) parsed = Math.max(minimum, parsed);
            if (Number.isFinite(maximum)) parsed = Math.min(maximum, parsed);
            return parsed;
        }

        function updateShortcode() {
            var modeEl = container.querySelector('.cm-sc-mode');
            var limitEl = container.querySelector('.cm-sc-limit');
            var depthEl = container.querySelector('.cm-sc-depth');
            var sortEl = container.querySelector('.cm-sc-sort');
            var layoutEl = container.querySelector('.cm-sc-layout');
            var nofollowEl = container.querySelector('.cm-sc-nofollow');
            var titleEl = container.querySelector('.cm-sc-display-title');
            var excludeEl = container.querySelector('.cm-sc-exclude');

            if (!modeEl || !limitEl) return;

            var mode = modeEl.value;
            var limit = String(boundedInteger(limitEl, 50));
            var layout = layoutEl ? layoutEl.value : 'list';
            var depth = layout === 'bare' ? '0' : (mode === 'flat' ? '-1' : String(boundedInteger(depthEl, 0)));
            var sort = (sortEl && sortEl.value === 'desc') ? 'desc' : 'asc';
            var nofollow = (nofollowEl && nofollowEl.value === 'true');
            var showTitle = titleEl ? titleEl.checked : true;
            var exclude = excludeEl ? excludeEl.value.trim() : '';
            var invalidExclude = /["\]\r\n]/.test(exclude);
            if (excludeEl) excludeEl.setAttribute('aria-invalid', invalidExclude ? 'true' : 'false');
			if (excludeError) {
				excludeError.textContent = invalidExclude
					? __( 'Remove double quotes, closing brackets, and line breaks.', 'cybermaps' )
					: '';
				excludeError.hidden = !invalidExclude;
			}

            var ptCheckboxes = container.querySelectorAll('.cm-sc-post-type:checked');
            var taxCheckboxes = container.querySelectorAll('.cm-sc-tax:checked');
            var pts = [];
            var taxs = [];
            ptCheckboxes.forEach(function(cb) { pts.push(cb.dataset.pt); });
            taxCheckboxes.forEach(function(cb) { taxs.push(cb.dataset.tax); });

            var allPTs = pts.indexOf('*') !== -1;
            var allTaxs = taxs.indexOf('*') !== -1;
			var allPTCheckbox = container.querySelector('.cm-sc-post-type[data-pt="*"]');
			var allTaxCheckbox = container.querySelector('.cm-sc-tax[data-tax="*"]');
            var tokenLabels = {};
			container.querySelectorAll('.cm-sc-post-type, .cm-sc-tax').forEach(function(cb) {
                var token = cb.dataset.pt || cb.dataset.tax;
                tokenLabels[token] = cb.dataset.label || token;
            });
			tokenLabels['post_type:*'] = allPTCheckbox ? allPTCheckbox.dataset.label : 'all publishable public post types';
			tokenLabels['taxonomy:*'] = allTaxCheckbox ? allTaxCheckbox.dataset.label : 'all public taxonomy archives';
			var selectedPTs = allPTs ? ['post_type:*'] : pts;
			var selectedTaxs = allTaxs ? ['taxonomy:*'] : taxs;
            var isDefaultPostSelection = allPTs && !allTaxs && taxs.length === 0;
            var onlyParts = isDefaultPostSelection
                ? []
                : Array.from(new Set(selectedPTs.concat(selectedTaxs)));

            if (!allPTs && !allTaxs && selectedPTs.length === 0 && selectedTaxs.length === 0) {
				preview.textContent = __( 'Select content to generate a shortcode', 'cybermaps' );
                desc.textContent = __( 'Select at least one post type or taxonomy.', 'cybermaps' );
                if (copyBtn) copyBtn.disabled = true;
                return;
            }
            if (copyBtn) copyBtn.disabled = false;
            var only = onlyParts.length > 0 ? ' only="' + onlyParts.join(',') + '"' : '';

            var sc = '[cybermap';
            if (only) sc += only;
            if (depth !== '0') sc += ' depth="' + depth + '"';
            if (limit !== '50') sc += ' limit="' + limit + '"';
            if (sort !== 'asc') sc += ' sort="desc"';
            if (layout !== 'list') sc += ' layout="' + layout + '"';
            if (nofollow) sc += ' nofollow="true"';
            if (!showTitle) sc += ' display_title="false"';
            if (exclude && !invalidExclude) sc += ' exclude="' + exclude + '"';
            sc += ']';

            preview.textContent = sc;
            var descText = layout === 'bare'
                ? __( 'Plain flat bullet lists', 'cybermaps' )
                : (
                    layout === 'columns'
                        ? __( 'Two-column grid', 'cybermaps' )
                        : (
                            mode === 'flat'
                                ? __( 'Flat list', 'cybermaps' )
                                : __( 'Hierarchical tree', 'cybermaps' )
                        )
                );
            if (isDefaultPostSelection) {
                descText += ' ' + __( 'of all publishable public post types', 'cybermaps' );
            } else if (allPTs && allTaxs) {
                descText += ' ' + __( 'of all publishable public post types and public taxonomies', 'cybermaps' );
            } else if (onlyParts.length > 0) {
                descText += ' ' + sprintf(
                    /* translators: %s: comma-separated post type and taxonomy names. */
                    __( 'of %s', 'cybermaps' ),
                    onlyParts.map(function(token) {
                        return tokenLabels[token] || token;
                    }).join(', ')
                );
            }
            descText += '. ' + sprintf(
                /* translators: %d: maximum total links rendered by the shortcode. */
                __( 'Maximum %d links total across all sections', 'cybermaps' ),
                Number( limit )
            );
            if (sort === 'desc') descText += ', ' + __( 'Z→A', 'cybermaps' );
            if (nofollow) descText += ', ' + __( 'nofollow', 'cybermaps' );
			if (!showTitle) descText += ', ' + __( 'no section headings', 'cybermaps' );
            descText += '.';
            if (invalidExclude) {
                desc.textContent = __( 'Remove double quotes, closing brackets, and line breaks from Exclude before copying the shortcode.', 'cybermaps' );
                if (copyBtn) copyBtn.disabled = true;
                return;
            }
            if (exclude) {
                descText += ' ' + sprintf(
                    /* translators: %s: comma-separated shortcode exclusions. */
                    __( 'Excluding: %s.', 'cybermaps' ),
                    exclude
                );
            }
            desc.textContent = descText;
        }

        container.addEventListener('input', function(e) {
            if (e.target.matches('.cm-sc-post-type, .cm-sc-tax, .cm-sc-mode, .cm-sc-limit, .cm-sc-depth, .cm-sc-sort, .cm-sc-layout, .cm-sc-nofollow, .cm-sc-display-title, .cm-sc-exclude')) {
                updateShortcode();
            }
        });
        container.addEventListener('change', function(e) {
            if (e.target.matches('.cm-sc-post-type[data-pt="*"]')) {
                var allCB = e.target;
                container.querySelectorAll('.cm-sc-post-type:not([data-pt="*"])').forEach(function(cb) {
                    cb.checked = false;
					cb.disabled = allCB.checked || cb.dataset.unavailable === '1';
                });
                updateShortcode();
            }
            if (e.target.matches('.cm-sc-tax[data-tax="*"]')) {
                var allCB2 = e.target;
                container.querySelectorAll('.cm-sc-tax:not([data-tax="*"])').forEach(function(cb) {
                    cb.checked = false;
					cb.disabled = allCB2.checked || cb.dataset.unavailable === '1';
                });
                updateShortcode();
            }
            if (e.target.matches('.cm-sc-mode, .cm-sc-layout')) {
                syncHierarchyControls();
                updateShortcode();
            }
        });
        container.addEventListener('focusout', function(e) {
            if (e.target.matches('.cm-sc-limit')) {
                e.target.value = String(boundedInteger(e.target, 50));
                updateShortcode();
            }
            if (e.target.matches('.cm-sc-depth')) {
                e.target.value = String(boundedInteger(e.target, 0));
                updateShortcode();
            }
        });

        function syncHierarchyControls() {
            var layout = container.querySelector('.cm-sc-layout');
            var mode = container.querySelector('.cm-sc-mode');
            var modeWrap = container.querySelector('#cm-sc-mode-wrap');
            var depthWrap = container.querySelector('#cm-sc-depth-wrap');
            var isBare = layout && layout.value === 'bare';
            if (modeWrap) modeWrap.style.display = isBare ? 'none' : '';
            if (depthWrap) depthWrap.style.display = isBare || (mode && mode.value === 'flat') ? 'none' : '';
        }

        if (copyBtn) {
			function reportCopy(message) {
				copyBtn.textContent = message;
				if (copyStatus) copyStatus.textContent = message;
				setTimeout(function() { copyBtn.textContent = __( 'Copy', 'cybermaps' ); }, 1500);
			}

			function fallbackCopy(text) {
				var ta = document.createElement('textarea');
				ta.value = text;
				ta.style.position = 'fixed';
				ta.style.opacity = '0';
				document.body.appendChild(ta);
				ta.select();
				var copied = false;
				try { copied = document.execCommand('copy'); } catch(e) { copied = false; }
				document.body.removeChild(ta);
				reportCopy(copied ? __( 'Copied!', 'cybermaps' ) : __( 'Copy failed', 'cybermaps' ));
			}

            copyBtn.addEventListener('click', function() {
                var text = preview.textContent;
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(function() {
						reportCopy(__( 'Copied!', 'cybermaps' ));
					}).catch(function() {
						fallbackCopy(text);
					});
                    return;
                }
				fallbackCopy(text);
            });
        }

        // Initialise disabled state for post type and taxonomy children.
        var allPT = container.querySelector('.cm-sc-post-type[data-pt="*"]');
        if (allPT && allPT.checked) {
            container.querySelectorAll('.cm-sc-post-type:not([data-pt="*"])').forEach(function(cb) {
                cb.checked = false;
                cb.disabled = true;
            });
        }
        var allTax = container.querySelector('.cm-sc-tax[data-tax="*"]');
        if (allTax && allTax.checked) {
            container.querySelectorAll('.cm-sc-tax:not([data-tax="*"])').forEach(function(cb) {
                cb.checked = false;
                cb.disabled = true;
            });
        }
        // Initialise layout/mode-dependent hierarchy controls.
        syncHierarchyControls();

        updateShortcode();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initBuilder);
    } else {
        initBuilder();
    }
})();
