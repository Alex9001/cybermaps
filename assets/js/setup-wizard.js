(function (wp, window, document) {
    'use strict';

    if (!wp || !wp.element || !window.cybermapsSetupWizard) {
        return;
    }

    var el = wp.element.createElement;
    var useEffect = wp.element.useEffect;
    var useState = wp.element.useState;
    var __ = wp.i18n && wp.i18n.__ ? wp.i18n.__ : function (value) { return value; };
    var config = window.cybermapsSetupWizard;

    var labels = {
        homepage: __('Homepage', 'cybermaps'), authors: __('Author archives', 'cybermaps'), archives: __('Date archives', 'cybermaps'), empty_terms: __('Empty terms', 'cybermaps'),
        none: __('No media discovery', 'cybermaps'), standard: __('Attachments and featured media', 'cybermaps'), advanced: __('Include embedded media', 'cybermaps'),
        video: __('Video schema', 'cybermaps'), multimodal: __('AI media hints', 'cybermaps'), news: __('Google News sitemap', 'cybermaps'), rss: __('Recent-content RSS sitemap', 'cybermaps'),
        html: __('HTML sitemap shortcode', 'cybermaps'), indexnow: __('IndexNow notifications', 'cybermaps'), websub: __('WebSub notifications', 'cybermaps'),
        redirect_core: __('Redirect WordPress Core sitemap', 'cybermaps'), robots: __('Advertise publications in robots.txt', 'cybermaps'), cache: __('Cache dynamic sitemap responses', 'cybermaps'),
        headers: __('Response discovery headers', 'cybermaps'), hints: __('Metadata excerpts', 'cybermaps'), sitemap_link: __('Sitemap link in LLMS', 'cybermaps'),
        full: __('Complete content file', 'cybermaps'), tldr: __('Budgeted site briefing', 'cybermaps'), rag: __('Literal retrieval chunks', 'cybermaps'), localized: __('Localized LLMS routes', 'cybermaps'),
        search_content: __('Declare content search', 'cybermaps'), read_articles: __('Declare article reading', 'cybermaps'), extract_entities: __('Declare entity extraction', 'cybermaps'),
        allow: __('Allow', 'cybermaps'), limited: __('Limited', 'cybermaps'), forbid: __('Forbid', 'cybermaps'), yes: __('Yes', 'cybermaps'), no: __('No', 'cybermaps'),
        off: __('Dynamic only', 'cybermaps'), well_known: __('.well-known compatibility files', 'cybermaps'), all: __('Full static publication', 'cybermaps'),
        anonymized: __('On with anonymized IP addresses', 'cybermaps'), full_ips: __('On with full IP addresses', 'cybermaps'),
        Organization: __('Organization', 'cybermaps'), LocalBusiness: __('Local Business', 'cybermaps'), Person: __('Person', 'cybermaps'),
        Service: __('Services', 'cybermaps'), Product: __('Products', 'cybermaps'),
        '': __('No assertion', 'cybermaps'), 'CC-BY-4.0': __('CC BY 4.0', 'cybermaps'), 'CC-BY-SA-4.0': __('CC BY-SA 4.0', 'cybermaps'),
        'CC-BY-NC-4.0': __('CC BY-NC 4.0', 'cybermaps'), 'CC-BY-ND-4.0': __('CC BY-ND 4.0', 'cybermaps'), 'All-Rights-Reserved': __('All rights reserved', 'cybermaps')
    };

    function label(value) {
        return labels[value] || value;
    }

    function ajax(action, data) {
        var body = new URLSearchParams();
        body.append('action', action);
        body.append('nonce', config.nonce);
        Object.keys(data || {}).forEach(function (key) {
            body.append(key, data[key]);
        });
        return window.fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (response) {
            return response.json();
        }).then(function (response) {
            if (!response || !response.success) {
                throw new Error(response && response.data && response.data.message ? response.data.message : config.strings.connectionError);
            }
            return response.data;
        });
    }

    function CheckboxGroup(props) {
        var selected = Array.isArray(props.value) ? props.value : [];
        return el('fieldset', { className: 'cm-setup-wizard__field' }, [
            el('legend', { key: 'legend' }, props.label),
            props.help ? el('p', { key: 'help', className: 'cm-setup-wizard__help' }, props.help) : null,
            el('div', { key: 'choices', className: 'cm-setup-wizard__choices' }, props.options.map(function (option) {
                var value = typeof option === 'string' ? option : option.value;
                var text = typeof option === 'string' ? label(option) : option.label;
                return el('label', { key: value }, [
                    el('input', {
                        type: 'checkbox', checked: selected.indexOf(value) !== -1,
                        onChange: function (event) {
                            var next = selected.slice();
                            if (event.target.checked && next.indexOf(value) === -1) {
                                next.push(value);
                            }
                            if (!event.target.checked) {
                                next = next.filter(function (item) { return item !== value; });
                            }
                            props.onChange(next);
                        }
                    }),
                    text
                ]);
            }))
        ]);
    }

    function RadioGroup(props) {
        return el('fieldset', { className: 'cm-setup-wizard__field' }, [
            el('legend', { key: 'legend' }, props.label),
            props.help ? el('p', { key: 'help', className: 'cm-setup-wizard__help' }, props.help) : null,
            el('div', { key: 'choices', className: 'cm-setup-wizard__choices' }, props.options.map(function (option) {
                var value = typeof option === 'string' ? option : option.value;
                var text = typeof option === 'string' ? label(option) : option.label;
                return el('label', { key: value }, [
                    el('input', {
                        type: 'radio', name: props.name, value: value, checked: props.value === value,
                        onChange: function () { props.onChange(value); }
                    }),
                    text
                ]);
            }))
        ]);
    }

    function TextField(props) {
		var value = props.value === null || typeof props.value === 'undefined' ? '' : props.value;
        var input = props.multiline ? el('textarea', {
            id: props.id, value: value, onChange: function (event) { props.onChange(event.target.value); }
        }) : el('input', {
            id: props.id, type: props.type || 'text', value: value, min: props.min,
            max: props.max, onChange: function (event) { props.onChange(event.target.value); }
        });
        return el('div', { className: 'cm-setup-wizard__field' }, [
            el('label', { key: 'label', htmlFor: props.id }, props.label),
            props.help ? el('p', { key: 'help', className: 'cm-setup-wizard__help' }, props.help) : null,
            input
        ]);
    }

    function Toggle(props) {
        return el('label', { className: 'cm-setup-wizard__checkbox' }, [
            el('input', { type: 'checkbox', checked: !!props.value, onChange: function (event) { props.onChange(event.target.checked); } }),
            el('span', null, props.label)
        ]);
    }

    function SectionMode(props) {
        var mode = props.mode;
        return el('section', { className: 'cm-card cm-setup-wizard__card' }, [
            el('h2', { key: 'title' }, props.title),
            el('p', { key: 'intro', className: 'cm-setup-wizard__intro' }, props.intro),
            el('fieldset', { key: 'mode', className: 'cm-setup-wizard__mode' }, [
                el('legend', { className: 'screen-reader-text' }, __('Section choice', 'cybermaps')),
                el('label', { key: 'keep' }, [
                    el('input', { type: 'radio', name: 'mode-' + props.section, checked: mode === 'keep', onChange: function () { props.onMode('keep'); } }),
                    el('strong', null, __('Keep current', 'cybermaps')),
                    el('span', null, __('Make no changes in this section.', 'cybermaps'))
                ]),
                el('label', { key: 'configure' }, [
                    el('input', { type: 'radio', name: 'mode-' + props.section, checked: mode === 'configure', onChange: function () { props.onMode('configure'); } }),
                    el('strong', null, __('Configure', 'cybermaps')),
                    el('span', null, __('Use guided recommendations and your answers.', 'cybermaps'))
                ]),
                el('label', { key: 'reset' }, [
                    el('input', { type: 'radio', name: 'mode-' + props.section, checked: mode === 'reset', onChange: function () { props.onMode('reset'); } }),
                    el('strong', null, __('Reset guided fields', 'cybermaps')),
                    el('span', null, __('Restore only fields owned by this wizard to defaults.', 'cybermaps'))
                ])
            ]),
            mode === 'configure' ? props.children : null,
            mode === 'reset' ? el('div', { className: 'cm-setup-wizard__warning' }, props.resetText || __('This reset preserves detailed settings that Guided Setup does not manage. You will confirm it again before applying.', 'cybermaps')) : null
        ]);
    }

    function ValueDisplay(value) {
        if (value === null || typeof value === 'undefined' || value === '') {
            return el('span', null, '—');
        }
        if (typeof value === 'object') {
            return el('pre', null, JSON.stringify(value, null, 2));
        }
        if (typeof value === 'boolean') {
            return el('span', null, value ? __('Enabled', 'cybermaps') : __('Disabled', 'cybermaps'));
        }
        return el('span', null, String(value));
    }

    function App() {
        var root = document.getElementById('cybermaps-setup-wizard-root');
        var overviewUrl = root ? root.getAttribute('data-overview-url') : '';
        var _state = useState(null), data = _state[0], setData = _state[1];
        var _step = useState(0), stepIndex = _step[0], setStepIndex = _step[1];
        var _answers = useState({}), answers = _answers[0], setAnswers = _answers[1];
        var _modes = useState({}), modes = _modes[0], setModes = _modes[1];
        var _error = useState(''), error = _error[0], setError = _error[1];
        var _loading = useState(true), loading = _loading[0], setLoading = _loading[1];
        var _preview = useState(null), previewData = _preview[0], setPreviewData = _preview[1];
        var _previewing = useState(false), previewing = _previewing[0], setPreviewing = _previewing[1];
        var _applying = useState(false), applying = _applying[0], setApplying = _applying[1];
        var _success = useState(null), success = _success[0], setSuccess = _success[1];
        var _ackHigh = useState(false), ackHigh = _ackHigh[0], setAckHigh = _ackHigh[1];
        var _ackReset = useState(false), ackReset = _ackReset[0], setAckReset = _ackReset[1];
        var _catalogQuery = useState(''), catalogQuery = _catalogQuery[0], setCatalogQuery = _catalogQuery[1];
        var _catalogResults = useState([]), catalogResults = _catalogResults[0], setCatalogResults = _catalogResults[1];

        useEffect(function () {
            ajax('cybermaps_setup_wizard_bootstrap', {}).then(function (result) {
                setData(result);
                setAnswers(result.answers || {});
                setModes(result.section_modes || {});
            }).catch(function (requestError) {
                setError(requestError.message || config.strings.connectionError);
            }).finally(function () {
                setLoading(false);
            });
        }, []);

        useEffect(function () {
            function beforeUnload(event) {
                if (!success && data && Object.keys(modes).some(function (key) { return modes[key] !== 'keep'; })) {
                    event.preventDefault();
                    event.returnValue = config.strings.leaveWarning;
                    return config.strings.leaveWarning;
                }
                return undefined;
            }
            window.addEventListener('beforeunload', beforeUnload);
            return function () { window.removeEventListener('beforeunload', beforeUnload); };
        }, [data, modes, success]);

        function updateAnswer(key, value) {
            setAnswers(function (previous) {
                var next = Object.assign({}, previous);
                next[key] = value;
                return next;
            });
            setPreviewData(null);
        }

        function updateMode(section, value) {
            setModes(function (previous) {
                var next = Object.assign({}, previous);
                next[section] = value;
                return next;
            });
            setPreviewData(null);
        }

        function payload() {
            return JSON.stringify({ wizard_version: data.wizard_version, section_modes: modes, answers: answers });
        }

        function showPreview() {
            setError('');
            setPreviewing(true);
            ajax('cybermaps_setup_wizard_preview', { payload: payload() }).then(function (result) {
                setPreviewData(result);
                setStepIndex(data.steps.length - 1);
            }).catch(function (requestError) {
                setError(requestError.message || config.strings.connectionError);
            }).finally(function () {
                setPreviewing(false);
            });
        }

        function next() {
            if (!data) { return; }
            if (stepIndex >= data.steps.length - 2) {
                showPreview();
                return;
            }
            setError('');
            setStepIndex(stepIndex + 1);
        }

        function previous() {
            setError('');
            setStepIndex(Math.max(0, stepIndex - 1));
        }

        function apply() {
            if (!previewData) { return; }
            setApplying(true);
            setError('');
            ajax('cybermaps_setup_wizard_apply', {
                payload: payload(),
                configuration: previewData.configuration,
                environment_hash: previewData.environment_hash,
                content_hash: previewData.preview.content_hash,
                configuration_hash: previewData.preview.configuration_hash,
                acknowledge_high_impact: ackHigh ? '1' : '0',
                acknowledge_reset: ackReset ? '1' : '0'
            }).then(function (result) {
                setSuccess(result);
            }).catch(function (requestError) {
                setError(requestError.message || config.strings.connectionError);
            }).finally(function () {
                setApplying(false);
            });
        }

        function chooseMedia(key, useUrl) {
            if (!window.wp || !window.wp.media) {
                setError(__('The WordPress media library is unavailable on this page.', 'cybermaps'));
                return;
            }
            var frame = window.wp.media({ title: __('Select image', 'cybermaps'), multiple: false, library: { type: 'image' } });
            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                if (key === 'report_branding_logo') {
                    setAnswers(function (previous) {
                        var next = Object.assign({}, previous);
                        next.report_branding = Object.assign({}, previous.report_branding || {}, {
                            agency_logo: attachment.url || ''
                        });
                        return next;
                    });
                    setPreviewData(null);
                    return;
                }
                updateAnswer(key, useUrl ? (attachment.url || '') : (attachment.id || 0));
            });
            frame.open();
        }

        function searchCatalogParents() {
            ajax('cybermaps_setup_wizard_search_pages', { query: catalogQuery, page: '1' }).then(function (result) {
                setCatalogResults(result.items || []);
            }).catch(function (requestError) {
                setError(requestError.message || config.strings.connectionError);
            });
        }

        if (loading) {
            return el('p', null, __('Loading Guided Setup…', 'cybermaps'));
        }
        if (!data) {
            return el('div', { className: 'notice notice-error inline' }, el('p', null, error || __('Guided Setup could not be loaded.', 'cybermaps')));
        }
        if (success) {
            var changed = success.result && success.result.changed_groups ? success.result.changed_groups.length : 0;
            return el('div', { className: 'cm-setup-wizard__success' }, [
                el('h2', { key: 'heading' }, __('Guided Setup applied', 'cybermaps')),
                el('p', { key: 'copy' }, changed ? __('Your reviewed Cybermaps settings were applied.', 'cybermaps') : __('The site already matched the reviewed settings.', 'cybermaps')),
                el('p', { key: 'links' }, [
                    el('a', { className: 'button button-primary', href: overviewUrl }, __('Return to Overview', 'cybermaps')),
                    ' ',
                    el('a', { className: 'button button-secondary', href: overviewUrl.replace('tab=dashboard', 'tab=ai') }, __('Review AI Publishing', 'cybermaps'))
                ])
            ]);
        }

        var step = data.steps[stepIndex];
        var card = renderStep(step, data, answers, modes, updateAnswer, updateMode, chooseMedia, catalogQuery, setCatalogQuery, catalogResults, searchCatalogParents);
        var isReview = step.id === 'review';
        var highImpact = previewData && previewData.preview && Array.isArray(previewData.preview.high_impact_changes) && previewData.preview.high_impact_changes.length > 0;
        var resetNeeded = previewData && Array.isArray(previewData.reset_sections) && previewData.reset_sections.length > 0;
        return el('div', { className: 'cm-setup-wizard' }, [
            el('ol', { key: 'progress', className: 'cm-setup-wizard__progress', 'aria-label': __('Guided Setup progress', 'cybermaps') }, data.steps.map(function (item, index) {
                return el('li', { key: item.id, 'aria-current': index === stepIndex ? 'step' : undefined }, (index + 1) + '. ' + item.label);
            })),
            error ? el('div', { key: 'error', className: 'notice notice-error inline cm-setup-wizard__error', role: 'alert' }, el('p', null, error)) : null,
            card,
            isReview && previewData ? el('section', { key: 'review', className: 'cm-card cm-setup-wizard__card' }, [
                el('h2', { key: 'heading' }, __('Review exact changes', 'cybermaps')),
                el('p', { key: 'copy', className: 'cm-setup-wizard__intro' }, __('Cybermaps has sanitized these values without saving them. Return to a step to revise its answers.', 'cybermaps')),
                el('div', { key: 'changes', className: 'cm-setup-wizard__review' }, renderChanges(previewData)),
                highImpact ? el(Toggle, { key: 'ack-high', value: ackHigh, onChange: setAckHigh, label: __('I understand that the marked high-impact changes affect public output, privacy, or physical publication.', 'cybermaps') }) : null,
                resetNeeded ? el(Toggle, { key: 'ack-reset', value: ackReset, label: __('I understand that Guided Setup will reset fields in: ', 'cybermaps') + previewData.reset_sections.join(', ') + '.', onChange: setAckReset }) : null
            ]) : null,
            el('div', { key: 'footer', className: 'cm-setup-wizard__footer' }, [
                el('button', { key: 'cancel', type: 'button', className: 'button button-link', onClick: function () { window.location.href = overviewUrl; } }, __('Cancel', 'cybermaps')),
                el('div', { key: 'actions', className: 'cm-setup-wizard__footer-end' }, [
                    stepIndex > 0 ? el('button', { key: 'previous', type: 'button', className: 'button button-secondary', onClick: previous, disabled: previewing || applying }, __('Back', 'cybermaps')) : null,
                    isReview ? el('button', { key: 'apply', type: 'button', className: 'button button-primary', onClick: apply, disabled: applying || !previewData || (highImpact && !ackHigh) || (resetNeeded && !ackReset) }, applying ? __('Applying…', 'cybermaps') : __('Apply reviewed settings', 'cybermaps')) : el('button', { key: 'next', type: 'button', className: 'button button-primary', onClick: next, disabled: previewing }, previewing ? __('Preparing review…', 'cybermaps') : __('Continue', 'cybermaps'))
                ])
            ])
        ]);
    }

    function renderChanges(previewData) {
        var changes = previewData.preview && Array.isArray(previewData.preview.changes) ? previewData.preview.changes : [];
        var effective = changes.filter(function (change) { return change.status !== 'unchanged'; });
        if (!effective.length) {
            return [el('p', { key: 'none' }, __('No settings would change. The current site already matches these answers.', 'cybermaps'))];
        }
        return effective.map(function (change, index) {
            var rationale = previewData.rationales && previewData.rationales[change.field] ? previewData.rationales[change.field] : __('Sanitized from your Guided Setup answers.', 'cybermaps');
            return el('article', { key: change.field + '-' + index, className: 'cm-setup-wizard__change' }, [
                el('h3', { key: 'title' }, change.label + ' · ' + change.section),
                change.high_impact ? el('p', { key: 'impact', className: 'cm-setup-wizard__high-impact' }, __('High impact', 'cybermaps')) : null,
                el('dl', { key: 'values' }, [
                    el('dt', { key: 'before-label' }, __('Current', 'cybermaps')), el('dd', { key: 'before' }, ValueDisplay(change.before)),
                    el('dt', { key: 'final-label' }, change.status === 'normalized' ? __('Final sanitized value', 'cybermaps') : __('New value', 'cybermaps')), el('dd', { key: 'final' }, ValueDisplay(change.final)),
                    el('dt', { key: 'why-label' }, __('Why', 'cybermaps')), el('dd', { key: 'why' }, rationale)
                ])
            ]);
        });
    }

    function renderStep(step, data, answers, modes, updateAnswer, updateMode, chooseMedia, catalogQuery, setCatalogQuery, catalogResults, searchCatalogParents) {
        if (step.id === 'profile') {
            var archetypes = data.analysis && data.analysis.archetypes ? data.analysis.archetypes : {};
            var profileOptions = Object.keys(archetypes).map(function (key) { return { value: key, label: archetypes[key] }; });
            return el(SectionMode, { section: 'strategy', mode: modes.strategy, onMode: function (value) { updateMode('strategy', value); }, title: __('Site Profile', 'cybermaps'), intro: data.analysis && data.analysis.reason ? data.analysis.reason : step.description }, [
                el(RadioGroup, { key: 'profile', name: 'strategy_profile', label: __('What best describes this site?', 'cybermaps'), value: answers.strategy_profile, options: profileOptions, onChange: function (value) { updateAnswer('strategy_profile', value); } }),
                el(Toggle, { key: 'clear', value: answers.strategy_clear_custom, onChange: function (value) { updateAnswer('strategy_clear_custom', value); }, label: __('Replace existing custom content-group adjustments with the selected profile baseline', 'cybermaps') })
            ]);
        }
        if (step.id === 'sitemaps') {
            return el(SectionMode, { section: 'sitemaps', mode: modes.sitemaps, onMode: function (value) { updateMode('sitemaps', value); }, title: __('XML & HTML Sitemaps', 'cybermaps'), intro: step.description }, [
                el(CheckboxGroup, { key: 'surfaces', label: __('Include these sitemap surfaces', 'cybermaps'), value: answers.sitemap_surfaces, options: ['homepage', 'authors', 'archives', 'empty_terms'], onChange: function (value) { updateAnswer('sitemap_surfaces', value); } }),
                el(RadioGroup, { key: 'media', name: 'sitemap_media', label: __('Media discovery depth', 'cybermaps'), value: answers.sitemap_media, options: ['none', 'standard', 'advanced'], onChange: function (value) { updateAnswer('sitemap_media', value); }, help: __('Standard covers attachments and featured media. Advanced also examines embedded media.', 'cybermaps') }),
                answers.sitemap_media !== 'none' ? el(CheckboxGroup, { key: 'media-features', label: __('Media publishing enhancements', 'cybermaps'), value: answers.sitemap_media_features, options: ['video', 'multimodal'], onChange: function (value) { updateAnswer('sitemap_media_features', value); } }) : null,
                el(CheckboxGroup, { key: 'specials', label: __('Specialized publications', 'cybermaps'), value: answers.sitemap_specials, options: ['news', 'rss', 'html', 'indexnow', 'websub'], onChange: function (value) { updateAnswer('sitemap_specials', value); }, help: __('IndexNow and WebSub can notify external services. WebSub also needs the AI Publication Hub.', 'cybermaps') }),
                Array.isArray(answers.sitemap_specials) && answers.sitemap_specials.indexOf('rss') !== -1 ? el(PostTypes, { key: 'rss-types', label: __('RSS content types', 'cybermaps'), types: data.public_types, value: answers.sitemap_rss_types, onChange: function (value) { updateAnswer('sitemap_rss_types', value); } }) : null,
                el(CheckboxGroup, { key: 'integration', label: __('WordPress integration', 'cybermaps'), value: answers.sitemap_integration, options: ['redirect_core', 'robots', 'cache'], onChange: function (value) { updateAnswer('sitemap_integration', value); } }),
                data.translation && data.translation.available ? el(Toggle, { key: 'translations', value: answers.sitemap_translations, onChange: function (value) { updateAnswer('sitemap_translations', value); }, label: __('Publish alternate-language sitemap relationships', 'cybermaps') }) : null
            ]);
        }
        if (step.id === 'ai') {
            var hub = !!answers.ai_hub;
            return el(SectionMode, { section: 'ai', mode: modes.ai, onMode: function (value) { updateMode('ai', value); }, title: __('AI Publishing & Usage', 'cybermaps'), intro: step.description }, [
                el(Toggle, { key: 'hub', value: hub, onChange: function (value) { updateAnswer('ai_hub', value); }, label: __('Enable the AI Publication Hub', 'cybermaps') }),
                hub ? el('div', { key: 'hub-fields' }, [
                    el(PostTypes, { key: 'types', label: __('LLMS and search content types', 'cybermaps'), types: data.public_types, value: answers.ai_types, onChange: function (value) { updateAnswer('ai_types', value); } }),
                    el(Toggle, { key: 'different', value: answers.ai_separate_sitemap_types, onChange: function (value) { updateAnswer('ai_separate_sitemap_types', value); }, label: __('Use different content types for the AI sitemap', 'cybermaps') }),
                    answers.ai_separate_sitemap_types ? el(PostTypes, { key: 'sitemap-types', label: __('AI sitemap content types', 'cybermaps'), types: data.public_types, value: answers.ai_sitemap_types, onChange: function (value) { updateAnswer('ai_sitemap_types', value); } }) : null,
                    el(CheckboxGroup, { key: 'features', label: __('AI publication features', 'cybermaps'), value: answers.ai_features, options: ['headers', 'hints', 'sitemap_link', 'full', 'tldr', 'rag'].concat(data.translation && data.translation.available ? ['localized'] : []), onChange: function (value) { updateAnswer('ai_features', value); } }),
                    el('div', { key: 'summaries', className: 'cm-setup-wizard__grid' }, [
                        el(TextField, { key: 'mission', id: 'cm-wizard-ai-mission', label: __('LLMS site summary', 'cybermaps'), multiline: true, value: answers.ai_mission, onChange: function (value) { updateAnswer('ai_mission', value); } }),
                        el(TextField, { key: 'description', id: 'cm-wizard-ai-description', label: __('AI Discovery Manifest summary', 'cybermaps'), multiline: true, value: answers.ai_business_description, onChange: function (value) { updateAnswer('ai_business_description', value); } })
                    ]),
                    el(TextField, { key: 'topics', id: 'cm-wizard-ai-topics', label: __('Site topics', 'cybermaps'), value: answers.ai_topics, onChange: function (value) { updateAnswer('ai_topics', value); }, help: __('Separate topical labels with commas.', 'cybermaps') }),
                    el(CheckboxGroup, { key: 'capabilities', label: __('Truthful capability declarations', 'cybermaps'), value: answers.ai_capabilities, options: ['search_content', 'read_articles', 'extract_entities'], onChange: function (value) { updateAnswer('ai_capabilities', value); }, help: __('These labels describe your published surface; they do not create a new external API.', 'cybermaps') }),
                    el('div', { key: 'usage', className: 'cm-setup-wizard__grid' }, [
                        el(RadioGroup, { key: 'rag', name: 'ai_usage_rag', label: __('Retrieval and RAG use', 'cybermaps'), value: answers.ai_usage_rag, options: ['allow', 'limited', 'forbid'], onChange: function (value) { updateAnswer('ai_usage_rag', value); } }),
                        el(RadioGroup, { key: 'training', name: 'ai_usage_training', label: __('Model training use', 'cybermaps'), value: answers.ai_usage_training, options: ['allow', 'forbid'], onChange: function (value) { updateAnswer('ai_usage_training', value); } }),
                        el(RadioGroup, { key: 'commercial', name: 'ai_usage_commercial', label: __('Commercial reuse', 'cybermaps'), value: answers.ai_usage_commercial, options: ['allow', 'forbid'], onChange: function (value) { updateAnswer('ai_usage_commercial', value); } }),
                        el(RadioGroup, { key: 'license', name: 'ai_license', label: __('Content license assertion', 'cybermaps'), value: answers.ai_license, options: ['', 'CC-BY-4.0', 'CC-BY-SA-4.0', 'CC-BY-NC-4.0', 'CC-BY-ND-4.0', 'All-Rights-Reserved'], onChange: function (value) { updateAnswer('ai_license', value); } })
                    ]),
                    el(TextField, { key: 'email', id: 'cm-wizard-ai-email', type: 'email', label: __('Public licensing email', 'cybermaps'), value: answers.ai_licensing_email, onChange: function (value) { updateAnswer('ai_licensing_email', value); } }),
                    el(Toggle, { key: 'signals', value: answers.ai_sync_signals, onChange: function (value) { updateAnswer('ai_sync_signals', value); }, label: __('Publish matching Content-Signal declarations in robots.txt', 'cybermaps') }),
                    answers.ai_sync_signals ? el(RadioGroup, { key: 'search', name: 'ai_signal_search', label: __('Search indexing preference', 'cybermaps'), value: answers.ai_signal_search, options: ['yes', 'no'], onChange: function (value) { updateAnswer('ai_signal_search', value); } }) : null
                ]) : el('p', { key: 'hub-off', className: 'cm-setup-wizard__note' }, __('Turning the Hub off changes only its master switch. Existing detailed AI configuration remains preserved.', 'cybermaps'))
            ]);
        }
        if (step.id === 'identity') {
            var type = answers.identity_type || 'Organization';
            var automated = data.identity && Array.isArray(data.identity.catalogs) ? data.identity.catalogs : [];
            return el(SectionMode, { section: 'identity', mode: modes.identity, onMode: function (value) { updateMode('identity', value); }, title: __('Public Identity & Automatic Catalog', 'cybermaps'), intro: step.description, resetText: __('This reset clears only Guided Setup identity fields. It preserves addresses, contacts, hours, social profiles, and every catalog.', 'cybermaps') }, [
                el(RadioGroup, { key: 'type', name: 'identity_type', label: __('What does this website represent?', 'cybermaps'), value: type, options: ['Organization', 'LocalBusiness', 'Person'], onChange: function (value) { updateAnswer('identity_type', value); } }),
                type !== 'Person' ? el(SelectField, { key: 'precise', id: 'cm-wizard-identity-precise', label: __('Specific Schema.org type', 'cybermaps'), value: answers.identity_precise_type, options: [{ value: '', label: __('No narrower type', 'cybermaps') }].concat((data.identity.types || []).filter(function (item) { return item !== 'Person'; }).map(function (item) { return { value: item, label: item }; })), onChange: function (value) { updateAnswer('identity_precise_type', value); } }) : null,
                el('div', { key: 'identity-text', className: 'cm-setup-wizard__grid' }, [
                    el(TextField, { key: 'name', id: 'cm-wizard-identity-name', label: __('Public identity name', 'cybermaps'), value: answers.identity_name, onChange: function (value) { updateAnswer('identity_name', value); } }),
                    el(TextField, { key: 'description', id: 'cm-wizard-identity-description', label: __('Public identity description', 'cybermaps'), multiline: true, value: answers.identity_description, onChange: function (value) { updateAnswer('identity_description', value); } })
                ]),
                el('div', { key: 'image', className: 'cm-setup-wizard__field' }, [
                    el('strong', { key: 'title' }, __('Identity image', 'cybermaps')),
                    el('p', { key: 'help', className: 'cm-setup-wizard__help' }, answers.identity_image_id ? __('A WordPress media attachment is selected.', 'cybermaps') : __('Optional public logo or identity image.', 'cybermaps')),
                    el('button', { key: 'button', type: 'button', className: 'button button-secondary', onClick: function () { chooseMedia('identity_image_id', false); } }, answers.identity_image_id ? __('Replace image', 'cybermaps') : __('Select image', 'cybermaps'))
                ]),
                type !== 'Person' ? el(Toggle, { key: 'kg-link', value: answers.identity_kg_link, onChange: function (value) { updateAnswer('identity_kg_link', value); }, label: __('Link this identity to the website in the Knowledge Graph', 'cybermaps') }) : null,
                el(RadioGroup, { key: 'catalog-action', name: 'catalog_action', label: __('Automatic catalog', 'cybermaps'), value: answers.catalog_action, options: [{ value: 'none', label: __('Do not change catalogs', 'cybermaps') }, { value: 'add', label: __('Add an automatic catalog', 'cybermaps') }].concat(automated.length ? [{ value: 'edit', label: __('Edit an automatic catalog', 'cybermaps') }] : []), onChange: function (value) {
                    updateAnswer('catalog_action', value);
                    if (value === 'edit' && automated.length) {
                        updateAnswer('catalog_index', automated[0].index);
                        updateAnswer('catalog_parent_id', automated[0].parent_id || 0);
                        updateAnswer('catalog_item_type', automated[0].item_type || 'Service');
                        updateAnswer('catalog_name', automated[0].name || '');
                    }
                }, help: __('An automatic catalog publishes Service or Product offers from the direct child pages of one parent Page.', 'cybermaps') }),
                answers.catalog_action === 'edit' ? el(SelectField, { key: 'catalog-index', id: 'cm-wizard-catalog-index', label: __('Automatic catalog to edit', 'cybermaps'), value: String(answers.catalog_index || 0), options: automated.map(function (catalog) { return { value: String(catalog.index), label: (catalog.name || __('Untitled catalog', 'cybermaps')) + ' · ' + catalog.item_type }; }), onChange: function (value) {
                    var index = parseInt(value, 10);
                    var selectedCatalog = automated.filter(function (catalog) { return catalog.index === index; })[0];
                    updateAnswer('catalog_index', index);
                    if (selectedCatalog) {
                        updateAnswer('catalog_parent_id', selectedCatalog.parent_id || 0);
                        updateAnswer('catalog_item_type', selectedCatalog.item_type || 'Service');
                        updateAnswer('catalog_name', selectedCatalog.name || '');
                    }
                } }) : null,
                answers.catalog_action === 'add' || answers.catalog_action === 'edit' ? el('div', { key: 'catalog-fields' }, [
                    el('div', { key: 'search', className: 'cm-setup-wizard__field' }, [
                        el('label', { key: 'label', htmlFor: 'cm-wizard-catalog-search' }, __('Find a parent Page', 'cybermaps')),
                        el('div', { key: 'row', className: 'cm-setup-wizard__footer-end' }, [
                            el('input', { key: 'input', id: 'cm-wizard-catalog-search', type: 'text', value: catalogQuery, onChange: function (event) { setCatalogQuery(event.target.value); } }),
                            el('button', { key: 'button', type: 'button', className: 'button button-secondary', onClick: searchCatalogParents }, __('Search pages', 'cybermaps'))
                        ]),
                        answers.catalog_parent_id ? el('p', { key: 'selected', className: 'cm-setup-wizard__note' }, __('Selected parent Page ID: ', 'cybermaps') + answers.catalog_parent_id) : null,
                        catalogResults.length ? el('div', { key: 'results', className: 'cm-setup-wizard__choices' }, catalogResults.map(function (result) {
                            return el('button', { key: result.id, type: 'button', className: 'button button-secondary', onClick: function () { updateAnswer('catalog_parent_id', result.id); } }, result.label + ' (' + result.children + ' child pages)');
                        })) : null
                    ]),
                    el('div', { key: 'catalog-meta', className: 'cm-setup-wizard__grid' }, [
                        el(RadioGroup, { key: 'item-type', name: 'catalog_item_type', label: __('Catalog items represent', 'cybermaps'), value: answers.catalog_item_type, options: ['Service', 'Product'], onChange: function (value) { updateAnswer('catalog_item_type', value); } }),
                        el(TextField, { key: 'name', id: 'cm-wizard-catalog-name', label: __('Catalog name', 'cybermaps'), value: answers.catalog_name, onChange: function (value) { updateAnswer('catalog_name', value); }, help: __('Optional; the parent Page title is used when blank.', 'cybermaps') })
                    ])
                ]) : null
            ]);
        }
        if (step.id === 'operations') {
            return el('div', null, [
                el(SectionMode, { key: 'analytics', section: 'analytics', mode: modes.analytics, onMode: function (value) { updateMode('analytics', value); }, title: __('Crawler Analytics', 'cybermaps'), intro: __('Choose whether Cybermaps records endpoint requests and how long it retains them.', 'cybermaps') }, [
                    el(RadioGroup, { key: 'mode', name: 'analytics_mode', label: __('Crawler analytics', 'cybermaps'), value: answers.analytics_mode, options: [{ value: 'off', label: __('Off', 'cybermaps') }, { value: 'anonymized', label: __('On with anonymized IP addresses', 'cybermaps') }, { value: 'full', label: __('On with full IP addresses', 'cybermaps') }], onChange: function (value) { updateAnswer('analytics_mode', value); } }),
                    answers.analytics_mode === 'full' ? el('div', { key: 'warning', className: 'cm-setup-wizard__warning' }, __('Full IP addresses are personal data in many jurisdictions. Confirm that this matches your privacy obligations.', 'cybermaps')) : null,
                    el(TextField, { key: 'retention', id: 'cm-wizard-retention', type: 'number', min: '1', max: '365', label: __('Retention days', 'cybermaps'), value: answers.analytics_retention, onChange: function (value) { updateAnswer('analytics_retention', value); } })
                ]),
                el(SectionMode, { key: 'delivery', section: 'delivery', mode: modes.delivery, onMode: function (value) { updateMode('delivery', value); }, title: __('Publication Delivery', 'cybermaps'), intro: data.multisite ? __('WordPress multisite is dynamic-only. Cybermaps will store the compatible Dynamic setting.', 'cybermaps') : __('Choose whether Cybermaps materializes eligible public publications as static files.', 'cybermaps') }, [
                    data.multisite ? el('p', { key: 'locked', className: 'cm-setup-wizard__warning' }, __('Static publication is disabled on multisite to prevent sites competing for shared web-root filenames.', 'cybermaps')) : el(RadioGroup, { key: 'mode', name: 'delivery_mode', label: __('Static File Engine delivery', 'cybermaps'), value: answers.delivery_mode, options: ['off', 'well_known', 'all'], onChange: function (value) { updateAnswer('delivery_mode', value); }, help: __('Core discovery files publish only the small extension-bearing JSON inventory. The Discovery Index and API Catalog remain dynamic.', 'cybermaps') })
                ]),
                el(SectionMode, { key: 'reports', section: 'reports', mode: modes.reports, onMode: function (value) { updateMode('reports', value); }, title: __('Reports', 'cybermaps'), intro: __('Set literal measurement thresholds and optionally brand client-ready reports.', 'cybermaps') }, [
                    el(ReportPolicy, { key: 'policy', value: answers.report_measurements || {}, onChange: function (value) { updateAnswer('report_measurements', value); } }),
                    el(Toggle, { key: 'branding-toggle', value: answers.report_branding_configure, onChange: function (value) { updateAnswer('report_branding_configure', value); }, label: __('Configure client report presentation', 'cybermaps') }),
                    answers.report_branding_configure ? el(ReportBranding, { key: 'branding', value: answers.report_branding || {}, themes: data.report_themes || {}, onChange: function (value) { updateAnswer('report_branding', value); }, chooseMedia: chooseMedia }) : null
                ])
            ]);
        }
        return el('section', { className: 'cm-card cm-setup-wizard__card' }, [
            el('h2', { key: 'heading' }, __('Ready to review', 'cybermaps')),
            el('p', { key: 'copy' }, __('Continue to ask Cybermaps for a non-writing preview of the exact settings changes.', 'cybermaps'))
        ]);
    }

    function PostTypes(props) {
        return el(CheckboxGroup, { label: props.label, value: props.value, options: (props.types || []).map(function (type) { return { value: type.name, label: type.label + (type.count ? ' (' + type.count + ')' : '') }; }), onChange: props.onChange });
    }

    function SelectField(props) {
        return el('div', { className: 'cm-setup-wizard__field' }, [
            el('label', { key: 'label', htmlFor: props.id }, props.label),
            el('select', { key: 'select', id: props.id, value: props.value || '', onChange: function (event) { props.onChange(event.target.value); } }, (props.options || []).map(function (option) {
                return el('option', { key: option.value, value: option.value }, option.label);
            }))
        ]);
    }

    function ReportPolicy(props) {
        var value = props.value || {};
        function set(key, nextValue) { props.onChange(Object.assign({}, value, (function () { var patch = {}; patch[key] = nextValue; return patch; })())); }
        return el('div', { className: 'cm-setup-wizard__grid' }, [
            el(TextField, { key: 'post-min', id: 'cm-wizard-post-min', type: 'number', min: '1', max: '10000', label: __('Post minimum words', 'cybermaps'), value: value.audit_post_min_words, onChange: function (nextValue) { set('audit_post_min_words', nextValue); } }),
            el(TextField, { key: 'post-age', id: 'cm-wizard-post-age', type: 'number', min: '0', max: '36500', label: __('Post review interval days', 'cybermaps'), value: value.audit_post_max_age_days, onChange: function (nextValue) { set('audit_post_max_age_days', nextValue); } }),
            el(Toggle, { key: 'post-media', value: value.audit_post_require_media, onChange: function (nextValue) { set('audit_post_require_media', nextValue); }, label: __('Review posts without an image or media block', 'cybermaps') }),
            el(TextField, { key: 'page-min', id: 'cm-wizard-page-min', type: 'number', min: '1', max: '10000', label: __('Page minimum words', 'cybermaps'), value: value.audit_page_min_words, onChange: function (nextValue) { set('audit_page_min_words', nextValue); } }),
            el(TextField, { key: 'page-age', id: 'cm-wizard-page-age', type: 'number', min: '0', max: '36500', label: __('Page review interval days', 'cybermaps'), value: value.audit_page_max_age_days, onChange: function (nextValue) { set('audit_page_max_age_days', nextValue); } }),
            el(Toggle, { key: 'page-media', value: value.audit_page_require_media, onChange: function (nextValue) { set('audit_page_require_media', nextValue); }, label: __('Review pages without an image or media block', 'cybermaps') })
        ]);
    }

    function ReportBranding(props) {
        var value = props.value || {};
        function set(key, nextValue) { props.onChange(Object.assign({}, value, (function () { var patch = {}; patch[key] = nextValue; return patch; })())); }
        return el('div', { className: 'cm-setup-wizard__grid' }, [
            el(TextField, { key: 'agency', id: 'cm-wizard-agency-name', label: __('Prepared by', 'cybermaps'), value: value.agency_name, onChange: function (nextValue) { set('agency_name', nextValue); } }),
            el(TextField, { key: 'agency-url', id: 'cm-wizard-agency-url', type: 'url', label: __('Prepared-by URL', 'cybermaps'), value: value.agency_url, onChange: function (nextValue) { set('agency_url', nextValue); } }),
            el(TextField, { key: 'client', id: 'cm-wizard-client-name', label: __('Client site name', 'cybermaps'), value: value.site_name_override, onChange: function (nextValue) { set('site_name_override', nextValue); } }),
            el(SelectField, { key: 'theme', id: 'cm-wizard-report-theme', label: __('Report theme', 'cybermaps'), value: value.report_theme || 'swiss', options: Object.keys(props.themes || {}).map(function (key) { return { value: key, label: props.themes[key] }; }), onChange: function (nextValue) { set('report_theme', nextValue); } }),
            el('div', { key: 'logo', className: 'cm-setup-wizard__field' }, [
                el('strong', { key: 'title' }, __('Prepared-by logo', 'cybermaps')),
                el('p', { key: 'help', className: 'cm-setup-wizard__help' }, value.agency_logo ? __('A public media URL is selected.', 'cybermaps') : __('Optional logo displayed in printable reports.', 'cybermaps')),
                el('button', { key: 'button', type: 'button', className: 'button button-secondary', onClick: function () { props.chooseMedia('report_branding_logo', true); } }, value.agency_logo ? __('Replace logo', 'cybermaps') : __('Select logo', 'cybermaps'))
            ])
        ]);
    }

    var mount = document.getElementById('cybermaps-setup-wizard-root');
    if (mount) {
        wp.element.render(el(App), mount);
    }
}(window.wp, window, document));
