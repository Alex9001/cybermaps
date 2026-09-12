(function (wp, window) {
    'use strict';

    if (!wp || !wp.element || !window.cybermapsSetupWizard) {
        return;
    }

    var el = wp.element.createElement;
    var useEffect = wp.element.useEffect;
    var useRef = wp.element.useRef;
    var useState = wp.element.useState;
    var __ = wp.i18n.__;
    var config = window.cybermapsSetupWizard;

    function ajax(action, fields) {
        var body = new window.URLSearchParams();
        body.append('action', action);
        body.append('nonce', config.nonce);
        Object.keys(fields || {}).forEach(function (key) {
            body.append(key, fields[key]);
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

    function ChoiceCards(props) {
        return el('div', { className: 'cm-quick-setup__choices cm-quick-setup__choices--' + (props.columns || 'two') }, Object.keys(props.choices || {}).map(function (value) {
            var choice = props.choices[value];
            var checked = props.value === value;
            return el('label', { key: value, className: 'cm-quick-choice' + (checked ? ' is-selected' : '') }, [
                el('input', { key: 'input', type: 'radio', name: props.name, value: value, checked: checked, onChange: function () { props.onChange(value); } }),
                el('span', { key: 'icon', className: 'cm-quick-choice__icon', 'aria-hidden': 'true' }, choice.icon),
                el('span', { key: 'copy', className: 'cm-quick-choice__copy' }, [
                    el('strong', { key: 'label' }, choice.label),
                    el('span', { key: 'description' }, choice.description)
                ]),
                el('span', { key: 'check', className: 'cm-quick-choice__check', 'aria-hidden': 'true' }, '✓')
            ]);
        }));
    }

    function Question(props) {
        return el('fieldset', { className: 'cm-quick-setup__question' }, [
            el('legend', { key: 'legend' }, props.title),
            props.copy ? el('p', { key: 'copy', className: 'cm-quick-setup__question-copy' }, props.copy) : null,
            props.children
        ]);
    }

    function WebsiteStep(props) {
        return el(Question, {
            title: __('What kind of website is this?', 'cybermaps'),
            copy: __('Pick the closest match. You can fine-tune everything later.', 'cybermaps')
        }, el(ChoiceCards, {
            name: 'website_type',
            value: props.answers.website_type,
            choices: props.data.choices.website_type,
            columns: 'grid',
            onChange: function (value) { props.update('website_type', value); }
        }));
    }

    function PrioritiesStep(props) {
        return el('div', null, [
            el(Question, {
                key: 'ai',
                title: __('Would you like AI tools to discover your content?', 'cybermaps')
            }, el(ChoiceCards, {
                name: 'ai_visibility',
                value: props.answers.ai_visibility,
                choices: props.data.choices.ai_visibility,
                onChange: function (value) { props.update('ai_visibility', value); }
            })),
            el(Question, {
                key: 'operations',
                title: __('What matters more to you?', 'cybermaps')
            }, el(ChoiceCards, {
                name: 'operations',
                value: props.answers.operations,
                choices: props.data.choices.operations,
                onChange: function (value) { props.update('operations', value); }
            })),
            props.data.multisite && props.answers.operations === 'performance' ? el('p', { key: 'multisite', className: 'cm-quick-setup__note' }, __('This is a multisite network, so Cybermaps will use its safe dynamic delivery mode.', 'cybermaps')) : null
        ]);
    }

    function IdentityStep(props) {
        return el('div', null, [
            el(Question, {
                key: 'type',
                title: __('This website represents…', 'cybermaps')
            }, el(ChoiceCards, {
                name: 'identity_type',
                value: props.answers.identity_type,
                choices: props.data.choices.identity_type,
                columns: 'three',
                onChange: function (value) { props.update('identity_type', value); }
            })),
            el('div', { key: 'fields', className: 'cm-quick-setup__identity' }, [
                el('label', { key: 'name' }, [
                    el('span', { key: 'label' }, __('Public name', 'cybermaps')),
                    el('input', { key: 'input', type: 'text', value: props.answers.identity_name || '', maxLength: 256, required: true, onChange: function (event) { props.update('identity_name', event.target.value); } })
                ]),
                el('label', { key: 'description', className: 'cm-quick-setup__wide' }, [
                    el('span', { key: 'label' }, __('A short introduction', 'cybermaps')),
                    el('textarea', { key: 'input', rows: 4, maxLength: 8192, value: props.answers.identity_description || '', onChange: function (event) { props.update('identity_description', event.target.value); }, placeholder: __('What do you do, make, write, or help people with?', 'cybermaps') })
                ]),
                el('div', { key: 'image', className: 'cm-quick-setup__image cm-quick-setup__wide' }, [
                    el('div', { key: 'copy' }, [
                        el('strong', { key: 'label' }, __('Logo or profile photo', 'cybermaps')),
                        el('span', { key: 'status' }, props.answers.identity_image_id ? __('Image selected', 'cybermaps') : __('Optional', 'cybermaps'))
                    ]),
                    el('div', { key: 'actions' }, [
                        el('button', { key: 'choose', type: 'button', className: 'button button-secondary', onClick: props.chooseImage }, props.answers.identity_image_id ? __('Change image', 'cybermaps') : __('Choose image', 'cybermaps')),
                        props.answers.identity_image_id ? el('button', { key: 'remove', type: 'button', className: 'button-link-delete', onClick: function () { props.update('identity_image_id', 0); } }, __('Remove', 'cybermaps')) : null
                    ])
                ])
            ])
        ]);
    }

    function displayValue(value) {
        if (value === true) { return __('On', 'cybermaps'); }
        if (value === false) { return __('Off', 'cybermaps'); }
        if (Array.isArray(value)) { return value.length ? value.join(', ') : __('None', 'cybermaps'); }
        if (value === '' || value === null || typeof value === 'undefined') { return __('Not set', 'cybermaps'); }
        if (typeof value === 'object') { return JSON.stringify(value); }
        return String(value);
    }

    function Receipt(props) {
        var choices = props.data.choices;
        var answers = props.answers;
        var changes = props.preview && props.preview.preview && Array.isArray(props.preview.preview.changes) ? props.preview.preview.changes : [];
        var warnings = props.result && props.result.result && Array.isArray(props.result.result.warnings) ? props.result.result.warnings : [];
        var summaries = [
            { icon: '◎', label: __('Website profile', 'cybermaps'), value: choices.website_type[answers.website_type].label },
            { icon: '✦', label: __('AI discovery', 'cybermaps'), value: choices.ai_visibility[answers.ai_visibility].label },
            { icon: '◔', label: __('Activity & delivery', 'cybermaps'), value: choices.operations[answers.operations].label },
            { icon: '◉', label: __('Public identity', 'cybermaps'), value: answers.identity_name }
        ];
        return el('section', { className: 'cm-quick-setup__complete' }, [
            el('div', { key: 'mark', className: 'cm-quick-setup__complete-mark', 'aria-hidden': 'true' }, '✓'),
            el('p', { key: 'eyebrow', className: 'cm-page-eyebrow' }, __('Quick Setup complete', 'cybermaps')),
            el('h2', { key: 'title' }, __('You’re all set', 'cybermaps')),
            el('p', { key: 'copy', className: 'cm-quick-setup__complete-copy' }, __('Cybermaps has a solid starting point. The full workspaces are ready whenever you want to go deeper.', 'cybermaps')),
            el('div', { key: 'summary', className: 'cm-quick-setup__summary' }, summaries.map(function (item) {
                return el('article', { key: item.label }, [
                    el('span', { key: 'icon', 'aria-hidden': 'true' }, item.icon),
                    el('div', { key: 'copy' }, [el('small', { key: 'label' }, item.label), el('strong', { key: 'value' }, item.value)])
                ]);
            })),
            el('details', { key: 'details', className: 'cm-quick-setup__receipt' }, [
                el('summary', { key: 'summary' }, __('See settings applied', 'cybermaps')),
                el('div', { key: 'rows' }, changes.map(function (change) {
                    return el('div', { key: change.field, className: 'cm-quick-setup__receipt-row' }, [
                        el('span', { key: 'label' }, change.label),
                        el('strong', { key: 'value' }, displayValue(change.final))
                    ]);
                }))
            ]),
            warnings.length ? el('div', { key: 'warnings', className: 'notice notice-warning inline' }, warnings.map(function (warning, index) {
                return el('p', { key: index }, warning);
            })) : null,
            el('div', { key: 'actions', className: 'cm-quick-setup__complete-actions' }, [
                el('a', { key: 'overview', className: 'button button-primary', href: props.overviewUrl }, __('Go to Overview', 'cybermaps')),
                el('a', { key: 'settings', className: 'button button-secondary', href: props.overviewUrl.replace('tab=dashboard', 'tab=ai') }, __('Explore AI Publishing', 'cybermaps'))
            ])
        ]);
    }

    function App() {
        var root = document.getElementById('cybermaps-setup-wizard-root');
        var overviewUrl = root ? root.getAttribute('data-overview-url') : '';
        var dataState = useState(null);
        var data = dataState[0];
        var setData = dataState[1];
        var answersState = useState({});
        var answers = answersState[0];
        var setAnswers = answersState[1];
        var stepState = useState(0);
        var stepIndex = stepState[0];
        var setStepIndex = stepState[1];
        var statusState = useState({ loading: true, saving: false, error: '' });
        var status = statusState[0];
        var setStatus = statusState[1];
        var previewState = useState(null);
        var preview = previewState[0];
        var setPreview = previewState[1];
        var resultState = useState(null);
        var result = resultState[0];
        var setResult = resultState[1];
        var completeState = useState(false);
        var complete = completeState[0];
        var setComplete = completeState[1];
        var allowExit = useRef(false);

        useEffect(function () {
            ajax('cybermaps_setup_wizard_bootstrap', {}).then(function (result) {
                setData(result);
                setAnswers(result.answers || {});
                setStatus({ loading: false, saving: false, error: '' });
            }).catch(function (error) {
                setStatus({ loading: false, saving: false, error: error.message || config.strings.connectionError });
            });
        }, []);

        useEffect(function () {
            var exitLink = document.querySelector('[data-quick-setup-exit]');
            function permitExit() { allowExit.current = true; }
            function warn(event) {
                if (data && !complete && !allowExit.current) {
                    event.preventDefault();
                    event.returnValue = config.strings.leaveWarning;
                    return config.strings.leaveWarning;
                }
            }
            if (exitLink) { exitLink.addEventListener('click', permitExit); }
            window.addEventListener('beforeunload', warn);
            return function () {
                if (exitLink) { exitLink.removeEventListener('click', permitExit); }
                window.removeEventListener('beforeunload', warn);
            };
        }, [data, complete, allowExit]);

        function update(key, value) {
            setAnswers(function (current) { return Object.assign({}, current, (function () { var next = {}; next[key] = value; return next; }())); });
            setStatus(function (current) { return Object.assign({}, current, { error: '' }); });
        }

        function validStep() {
            if (stepIndex === 0) { return !!answers.website_type; }
            if (stepIndex === 1) { return !!answers.ai_visibility && !!answers.operations; }
            return !!answers.identity_type && !!String(answers.identity_name || '').trim();
        }

        function chooseImage() {
            if (!window.wp || !window.wp.media) { return; }
            var frame = window.wp.media({ title: __('Choose a public image', 'cybermaps'), multiple: false, library: { type: 'image' } });
            frame.on('select', function () {
                var image = frame.state().get('selection').first().toJSON();
                update('identity_image_id', image.id || 0);
            });
            frame.open();
        }

        function payload() {
            return JSON.stringify({ wizard_version: data.wizard_version, answers: answers });
        }

        function finish() {
            if (!validStep() || status.saving) { return; }
            setStatus({ loading: false, saving: true, error: '' });
            ajax('cybermaps_setup_wizard_preview', { payload: payload() }).then(function (result) {
                setPreview(result);
                return ajax('cybermaps_setup_wizard_apply', {
                    payload: payload(),
                    configuration: result.configuration,
                    environment_hash: result.environment_hash,
                    content_hash: result.preview.content_hash,
                    configuration_hash: result.preview.configuration_hash
                });
            }).then(function (applyResult) {
                setResult(applyResult);
                allowExit.current = true;
                setComplete(true);
                setStatus({ loading: false, saving: false, error: '' });
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }).catch(function (error) {
                setStatus({ loading: false, saving: false, error: error.message || config.strings.connectionError });
            });
        }

        if (status.loading) {
            return el('div', { className: 'cm-quick-setup__loading' }, [el('span', { key: 'spinner', className: 'spinner is-active' }), el('p', { key: 'copy' }, __('Getting your site ready…', 'cybermaps'))]);
        }
        if (!data) {
            return el('div', { className: 'notice notice-error inline' }, el('p', null, status.error || __('Quick Setup could not be loaded.', 'cybermaps')));
        }
        if (complete) {
            return el(Receipt, { data: data, answers: answers, preview: preview, result: result, overviewUrl: overviewUrl });
        }

        var step = data.steps[stepIndex];
        var content = step.id === 'website' ? el(WebsiteStep, { data: data, answers: answers, update: update })
            : step.id === 'priorities' ? el(PrioritiesStep, { data: data, answers: answers, update: update })
                : el(IdentityStep, { data: data, answers: answers, update: update, chooseImage: chooseImage });

        return el('div', { className: 'cm-quick-setup' }, [
            el('div', { key: 'progress', className: 'cm-quick-setup__progress' }, [
                el('span', { key: 'count' }, (stepIndex + 1) + ' ' + __('of', 'cybermaps') + ' ' + data.steps.length),
                el('div', { key: 'track', className: 'cm-quick-setup__track', 'aria-hidden': 'true' }, el('span', { style: { width: (((stepIndex + 1) / data.steps.length) * 100) + '%' } })),
                el('strong', { key: 'label' }, step.label)
            ]),
            el('section', { key: 'card', className: 'cm-quick-setup__card' }, [
                el('header', { key: 'header' }, [el('h2', { key: 'title' }, step.label), el('p', { key: 'copy' }, step.description)]),
                status.error ? el('div', { key: 'error', className: 'notice notice-error inline', role: 'alert' }, el('p', null, status.error)) : null,
                el('div', { key: 'content', className: 'cm-quick-setup__content' }, content),
                el('footer', { key: 'footer' }, [
                    el('button', { key: 'exit', type: 'button', className: 'button-link', onClick: function () { allowExit.current = true; window.location.href = overviewUrl; } }, __('Exit setup', 'cybermaps')),
                    el('div', { key: 'actions' }, [
                        stepIndex > 0 ? el('button', { key: 'back', type: 'button', className: 'button button-secondary', disabled: status.saving, onClick: function () { setStepIndex(stepIndex - 1); } }, __('Back', 'cybermaps')) : null,
                        stepIndex < data.steps.length - 1 ? el('button', { key: 'next', type: 'button', className: 'button button-primary', disabled: !validStep(), onClick: function () { setStepIndex(stepIndex + 1); window.scrollTo({ top: 0, behavior: 'smooth' }); } }, __('Continue', 'cybermaps')) : el('button', { key: 'finish', type: 'button', className: 'button button-primary', disabled: !validStep() || status.saving, onClick: finish }, status.saving ? __('Applying your setup…', 'cybermaps') : __('Finish setup', 'cybermaps'))
                    ])
                ])
            ])
        ]);
    }

    var mount = document.getElementById('cybermaps-setup-wizard-root');
    if (mount) {
        if (typeof wp.element.createRoot === 'function') {
            wp.element.createRoot(mount).render(el(App));
        } else {
            wp.element.render(el(App), mount);
        }
    }
}(window.wp, window));
