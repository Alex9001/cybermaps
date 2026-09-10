(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        const stateInput = document.getElementById('cybermaps_discovery_center');
        if (!stateInput) return;

        let state = {};
        let archetypes = {};
        let pendingSuggestion = null;
        try {
            state = JSON.parse(stateInput.value || '{}');
        } catch (error) {
            state = {};
        }
        try {
            archetypes = JSON.parse(stateInput.dataset.archetypes || '{}');
        } catch (error) {
            archetypes = {};
        }

        ['overrides', 'type_intents', 'disabled'].forEach(function(key) {
            if (!state[key] || typeof state[key] !== 'object') state[key] = {};
        });

        const archetypeSelect = document.getElementById('cybermaps-archetype-selector');
        const resetButton = document.getElementById('cybermaps-reset-blueprint');
        const analyzeButton = document.getElementById('cybermaps-resync-blueprint');
        const applyButton = document.getElementById('cybermaps-apply-blueprint');
        const dismissButton = document.getElementById('cybermaps-dismiss-blueprint');
        const suggestionBox = document.getElementById('cybermaps-blueprint-suggestion');
        const suggestionTitle = document.getElementById('cybermaps-blueprint-suggestion-title');
        const suggestionEvidence = document.getElementById('cybermaps-blueprint-suggestion-evidence');
        const statusBox = document.getElementById('cybermaps-blueprint-status');
        const errorBox = document.getElementById('cybermaps-blueprint-error');
        const settingsForm = stateInput.closest('form');
        const offLabel = stateInput.dataset.offLabel || 'Off';
        let hasUnsavedChanges = false;

        function persist() {
            stateInput.value = JSON.stringify(state);
            hasUnsavedChanges = true;
        }

        function getBaseType(row) {
            return row.dataset.baseType || row.dataset.type;
        }

        function formatStatus(template, first, second) {
            return String(template || '')
                .replace('%1$s', String(first))
                .replace('%2$s', String(second))
                .replace('%s', String(first));
        }

        function profileLabel(archetype) {
            if (!archetypeSelect) return archetype || '';
            const option = Array.prototype.find.call(archetypeSelect.options, function(item) {
                return item.value === archetype;
            });
            return option ? option.text : archetype;
        }

        function announce(message) {
            if (statusBox) statusBox.textContent = message;
        }

        function customStatus(count) {
            if (count === 0) return stateInput.dataset.customZeroLabel || '';
            if (count === 1) return stateInput.dataset.customOneLabel || '';
            return formatStatus(stateInput.dataset.customManyLabel, count, '');
        }

        function profileStatus(archetype, count) {
            const label = profileLabel(archetype);
            if (count === 0) return formatStatus(stateInput.dataset.profileZeroLabel, label, '');
            if (count === 1) return formatStatus(stateInput.dataset.profileOneLabel, label, '');
            return formatStatus(stateInput.dataset.profileManyLabel, label, count);
        }

        function evidenceStatus(count) {
            if (count === 0) return stateInput.dataset.suggestionEvidenceZero || '';
            if (count === 1) return stateInput.dataset.suggestionEvidenceOne || '';
            return formatStatus(stateInput.dataset.suggestionEvidenceMany, count, '');
        }

        function hideError() {
            if (!errorBox) return;
            errorBox.textContent = '';
            errorBox.style.display = 'none';
        }

        function hideSuggestion() {
            pendingSuggestion = null;
            if (suggestionBox) suggestionBox.hidden = true;
        }

        function customRowCount() {
            const keys = new Set();
            ['overrides', 'type_intents', 'disabled'].forEach(function(mapName) {
                Object.keys(state[mapName] || {}).forEach(function(key) {
                    keys.add(key);
                });
            });
            return keys.size;
        }

        function rowIsCustom(row) {
            const type = row.dataset.type;
            return ['overrides', 'type_intents', 'disabled'].some(function(mapName) {
                return Object.prototype.hasOwnProperty.call(state[mapName], type);
            });
        }

        function updateSource(row) {
            const custom = rowIsCustom(row);
            const badge = row.querySelector('.cm-matrix-source-badge');
            const reset = row.querySelector('.cm-matrix-reset-row');
            if (badge) {
                badge.className = 'cm-matrix-source-badge ' + (custom ? 'is-custom' : 'is-profile');
                badge.textContent = custom
                    ? (stateInput.dataset.customSourceLabel || 'Custom')
                    : (stateInput.dataset.profileSourceLabel || 'Baseline');
            }
            if (reset) reset.hidden = !custom;
        }

        function updateAllSources() {
            document.querySelectorAll('.cm-matrix-row').forEach(updateSource);
        }

        function priorityForRow(row, blueprint) {
            const type = row.dataset.type;
            if (
                Object.prototype.hasOwnProperty.call(state.overrides, type)
                && Number.isFinite(parseFloat(state.overrides[type]))
                && parseFloat(state.overrides[type]) > 0
            ) {
                return parseFloat(state.overrides[type]);
            }

            const baseType = getBaseType(row);
            const baseline = parseFloat(
                Object.prototype.hasOwnProperty.call(blueprint, baseType)
                    ? blueprint[baseType]
                    : 0.5
            );
            return Number.isFinite(baseline) && baseline > 0 ? baseline : 0.5;
        }

        function intentForRow(row, intents) {
            const type = row.dataset.type;
            if (Object.prototype.hasOwnProperty.call(state.type_intents, type)) {
                return state.type_intents[type] === 'transactional'
                    ? 'transactional'
                    : 'informational';
            }
            const baseType = getBaseType(row);
            return intents[baseType] === 'transactional' ? 'transactional' : 'informational';
        }

        function updatePriority(row, value, enabled) {
            const slider = row.querySelector('.cm-matrix-slider');
            const output = row.querySelector('.cm-matrix-slider-val');
            const toggle = row.querySelector('.cm-matrix-off-toggle');
            const intent = row.querySelector('.cm-intent-select');
            const candidate = Number.isFinite(value) && value > 0 ? value : 0.5;
            const normalized = Math.min(1, Math.max(0.1, candidate));

            row.dataset.lastValue = normalized.toString();
            row.classList.toggle('is-disabled', !enabled);
            if (toggle) toggle.checked = enabled;
            if (intent) intent.disabled = !enabled;
            if (slider) {
                slider.disabled = !enabled;
                slider.value = normalized.toFixed(1);
                slider.setAttribute('aria-valuenow', normalized.toFixed(1));
                slider.setAttribute('aria-valuetext', enabled ? normalized.toFixed(1) : offLabel);
            }
            if (output) output.textContent = enabled ? normalized.toFixed(1) : offLabel;
        }

        function updateIntent(row, intent) {
            const select = row.querySelector('.cm-intent-select');
            if (select) select.value = intent === 'transactional' ? 'transactional' : 'informational';
        }

        function renderProfile(archetype, clearCustom, blueprint, intents, messageKind) {
            state.archetype = archetype;
            if (clearCustom) {
                state.overrides = {};
                state.type_intents = {};
                state.disabled = {};
            }
            if (archetypeSelect) archetypeSelect.value = archetype;

            document.querySelectorAll('.cm-matrix-row').forEach(function(row) {
                const type = row.dataset.type;
                const enabled = !Object.prototype.hasOwnProperty.call(state.disabled, type);
                updatePriority(row, priorityForRow(row, blueprint), enabled);
                updateIntent(row, intentForRow(row, intents));
            });

            persist();
            updateAllSources();
            hideError();
            hideSuggestion();
            const count = customRowCount();
            if (messageKind === 'reset') {
                announce(formatStatus(stateInput.dataset.resetLabel, profileLabel(archetype), ''));
            } else if (messageKind === 'applied') {
                announce(formatStatus(stateInput.dataset.suggestionAppliedLabel, profileLabel(archetype), ''));
            } else {
                announce(profileStatus(archetype, count));
            }
        }

        function renderSelectedProfile(clearCustom, messageKind) {
            if (!archetypeSelect) return;
            const archetype = archetypeSelect.value;
            const defaults = archetypes[archetype] || {};
            renderProfile(
                archetype,
                clearCustom,
                defaults.blueprint || {},
                defaults.intents || {},
                messageKind
            );
        }

        function announceCustom() {
            announce(customStatus(customRowCount()));
        }

        document.querySelectorAll('.cm-matrix-slider').forEach(function(slider) {
            slider.addEventListener('input', function() {
                const row = this.closest('.cm-matrix-row');
                const value = parseFloat(this.value);
                state.overrides[row.dataset.type] = value;
                updatePriority(row, value, true);
                persist();
                updateSource(row);
                announceCustom();
            });
        });

        document.querySelectorAll('.cm-matrix-off-toggle').forEach(function(toggle) {
            toggle.addEventListener('change', function() {
                const row = this.closest('.cm-matrix-row');
                const type = row.dataset.type;
                const defaults = archetypes[state.archetype] || {};
                const blueprint = defaults.blueprint || {};

                if (this.checked) {
                    delete state.disabled[type];
                } else {
                    state.disabled[type] = true;
                }

                updatePriority(row, priorityForRow(row, blueprint), this.checked);
                persist();
                updateSource(row);
                announceCustom();
            });
        });

        document.querySelectorAll('.cm-intent-select').forEach(function(select) {
            select.addEventListener('change', function() {
                const row = this.closest('.cm-matrix-row');
                state.type_intents[row.dataset.type] = this.value === 'transactional'
                    ? 'transactional'
                    : 'informational';
                persist();
                updateSource(row);
                announceCustom();
            });
        });

        document.querySelectorAll('.cm-matrix-reset-row').forEach(function(button) {
            button.addEventListener('click', function(event) {
                event.preventDefault();
                const row = this.closest('.cm-matrix-row');
                const type = row.dataset.type;
                const defaults = archetypes[state.archetype] || {};
                delete state.overrides[type];
                delete state.type_intents[type];
                delete state.disabled[type];
                updatePriority(row, priorityForRow(row, defaults.blueprint || {}), true);
                updateIntent(row, intentForRow(row, defaults.intents || {}));
                persist();
                updateSource(row);
                announceCustom();
                const focusTarget = row.querySelector('.cm-matrix-off-toggle');
                if (focusTarget) focusTarget.focus();
            });
        });

        if (archetypeSelect) {
            archetypeSelect.addEventListener('change', function() {
                renderSelectedProfile(false, 'profile');
            });
        }

        if (resetButton) {
            resetButton.addEventListener('click', function(event) {
                event.preventDefault();
                renderSelectedProfile(true, 'reset');
            });
        }

        if (analyzeButton) {
            analyzeButton.addEventListener('click', function(event) {
                event.preventDefault();
                if (analyzeButton.classList.contains('updating')) return;

                analyzeButton.classList.add('updating');
                analyzeButton.disabled = true;
                analyzeButton.setAttribute('aria-busy', 'true');
                const originalText = analyzeButton.textContent;
                analyzeButton.textContent = analyzeButton.dataset.scanningLabel || originalText;
                hideError();
                hideSuggestion();

                const formData = new FormData();
                formData.append('action', 'cybermaps_scan_blueprint');
                formData.append('nonce', stateInput.dataset.nonce || '');

                fetch(ajaxurl, { method: 'POST', body: formData })
                    .then(function(response) {
                        return response.json().catch(function() {
                            return null;
                        }).then(function(payload) {
                            if (!response.ok) {
                                const serverMessage = payload && payload.data
                                    && typeof payload.data.message === 'string'
                                    ? payload.data.message
                                    : '';
                                const responseError = new Error(
                                    serverMessage || (analyzeButton.dataset.errorLabel || '')
                                );
                                responseError.userFacing = Boolean(serverMessage);
                                throw responseError;
                            }
                            return payload;
                        });
                    })
                    .then(function(response) {
                        if (!response.success || !response.data || !response.data.archetype) {
                            const responseMessage = response && response.data && response.data.message
                                ? response.data.message
                                : (analyzeButton.dataset.errorLabel || '');
                            const responseError = new Error(responseMessage);
                            responseError.userFacing = Boolean(
                                response && response.data && typeof response.data.message === 'string'
                            );
                            throw responseError;
                        }

                        pendingSuggestion = response.data;
                        const totalPosts = response.data.stats && response.data.stats.total_posts
                            ? parseInt(response.data.stats.total_posts, 10)
                            : 0;
                        const suggestedLabel = profileLabel(response.data.archetype);
                        if (suggestionTitle) {
                            suggestionTitle.textContent = formatStatus(
                                stateInput.dataset.suggestionTitle,
                                suggestedLabel,
                                ''
                            );
                        }
                        if (suggestionEvidence) {
                            const count = Number.isFinite(totalPosts) ? totalPosts : 0;
                            const evidence = typeof response.data.reason === 'string'
                                && response.data.reason.trim() !== ''
                                ? response.data.reason.trim()
                                : evidenceStatus(count);
                            const resetWarning = stateInput.dataset.suggestionResetWarning || '';
                            suggestionEvidence.textContent = [evidence, resetWarning]
                                .filter(Boolean)
                                .join(' ');
                        }
                        if (suggestionBox) suggestionBox.hidden = false;
                        announce(formatStatus(stateInput.dataset.suggestionTitle, suggestedLabel, ''));
                    })
                    .catch(function(error) {
                        if (errorBox) {
                            errorBox.textContent = error && error.userFacing && error.message
                                ? error.message
                                : (analyzeButton.dataset.errorLabel || '');
                            errorBox.style.display = 'block';
                        }
                    })
                    .then(function() {
                        analyzeButton.classList.remove('updating');
                        analyzeButton.disabled = false;
                        analyzeButton.removeAttribute('aria-busy');
                        analyzeButton.textContent = originalText;
                    });
            });
        }

        if (applyButton) {
            applyButton.addEventListener('click', function(event) {
                event.preventDefault();
                if (!pendingSuggestion || !pendingSuggestion.archetype) return;
                const defaults = archetypes[pendingSuggestion.archetype] || {};
                renderProfile(
                    pendingSuggestion.archetype,
                    true,
                    pendingSuggestion.blueprint || defaults.blueprint || {},
                    pendingSuggestion.intents || defaults.intents || {},
                    'applied'
                );
                if (archetypeSelect) archetypeSelect.focus();
            });
        }

        if (dismissButton) {
            dismissButton.addEventListener('click', function(event) {
                event.preventDefault();
                hideSuggestion();
                announce(stateInput.dataset.suggestionDismissedLabel || '');
                if (analyzeButton) analyzeButton.focus();
            });
        }

        if (settingsForm) {
            settingsForm.addEventListener('submit', function() {
                hasUnsavedChanges = false;
            });
        }

        window.addEventListener('beforeunload', function(event) {
            if (!hasUnsavedChanges) return;
            event.preventDefault();
            event.returnValue = '';
        });
    });
})();
