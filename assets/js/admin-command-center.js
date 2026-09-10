const { createRoot, render, useEffect, useState, createElement: el, Fragment } = wp.element;
const { 
    Button, 
    Modal, 
    Spinner, 
    Notice,
    RadioControl,
    CheckboxControl
} = wp.components;
const { __, _n, sprintf } = wp.i18n;
const apiFetch = wp.apiFetch;

const announce = ( message ) => {
    if ( message && wp.a11y && typeof wp.a11y.speak === 'function' ) {
        wp.a11y.speak( message, 'assertive' );
    }
};

const postAdminAjax = ( fields ) => {
    const body = new window.URLSearchParams();
    Object.entries( fields ).forEach( ( [ key, value ] ) => {
        body.set( key, String( value ?? '' ) );
    } );

    return apiFetch( {
        url: window.ajaxurl,
        method: 'POST',
        body,
    } );
};

// Safe mount helper
const mount = ( targetEl, component ) => {
    if ( ! targetEl ) return;
    if ( createRoot ) {
        createRoot( targetEl ).render( component );
    } else if ( render ) {
        render( component, targetEl );
    }
};

/**
 * Supply the WordPress 7.1 toggletip interaction contract on WordPress 7.0.
 * Native Core markup does not carry these Cybermaps-specific classes.
 */
const initLegacyToggletips = () => {
	const openSelector = '.cybermaps-legacy-toggletip__bubble:not([hidden])';

	const closeToggletip = ( bubble, restoreFocus = false ) => {
		if ( ! bubble ) return;
		const wrapper = bubble.closest( '.cybermaps-legacy-toggletip' );
		const toggle = wrapper?.querySelector( '.cybermaps-legacy-toggletip__toggle' );
		bubble.hidden = true;
		toggle?.setAttribute( 'aria-expanded', 'false' );
		if ( restoreFocus ) toggle?.focus();
	};

	document.addEventListener( 'click', ( event ) => {
		const toggle = event.target.closest( '.cybermaps-legacy-toggletip__toggle' );
		if ( toggle ) {
			const wrapper = toggle.closest( '.cybermaps-legacy-toggletip' );
			const bubble = wrapper?.querySelector( '.cybermaps-legacy-toggletip__bubble' );
			const willOpen = Boolean( bubble?.hidden );
			document.querySelectorAll( openSelector ).forEach( ( openBubble ) => {
				if ( openBubble !== bubble ) closeToggletip( openBubble );
			} );
			if ( bubble ) {
				bubble.hidden = ! willOpen;
				toggle.setAttribute( 'aria-expanded', willOpen ? 'true' : 'false' );
				if ( willOpen ) bubble.focus();
			}
			return;
		}

		const close = event.target.closest( '.cybermaps-legacy-toggletip__close' );
		if ( close ) {
			closeToggletip( close.closest( '.cybermaps-legacy-toggletip__bubble' ), true );
			return;
		}

		if ( ! event.target.closest( '.cybermaps-legacy-toggletip' ) ) {
			document.querySelectorAll( openSelector ).forEach( ( bubble ) => closeToggletip( bubble ) );
		}
	} );

	document.addEventListener( 'keydown', ( event ) => {
		if ( 'Escape' !== event.key ) return;
		const bubble = document.querySelector( openSelector );
		if ( bubble ) {
			event.preventDefault();
			closeToggletip( bubble, true );
		}
	} );
};

initLegacyToggletips();

/**
 * Core-owned static-file cleanup component.
 */
const NuclearPurge = () => {
    const [ isModalOpen, setIsModalOpen ] = useState( false );
    const [ isPurging, setIsPurging ] = useState( false );
    const [ feedback, setFeedback ] = useState( null );

    const summarizePurge = ( result ) => {
        const deleted = Array.isArray( result?.deleted ) ? result.deleted.length : 0;
        const retained = result?.retained && typeof result.retained === 'object'
            ? Object.keys( result.retained ).length
            : 0;

        if ( result?.status === 'busy' ) {
            return __( 'Static-file cleanup is busy. Try again after the current sync finishes.', 'cybermaps' );
        }
        if ( retained > 0 ) {
            return __( 'Static-file cleanup finished.', 'cybermaps' )
                + ` ${deleted} `
                + __( 'Core-owned files removed;', 'cybermaps' )
                + ` ${retained} `
                + __( 'modified or conflicting files retained.', 'cybermaps' );
        }

        return __( 'Static-file cleanup finished.', 'cybermaps' )
            + ` ${deleted} `
            + __( 'Core-owned files removed; no conflicts retained.', 'cybermaps' );
    };

    const handlePurge = async () => {
        setIsPurging( true );
        setFeedback( null );
        try {
            const response = await apiFetch( {
                path: 'cybermaps/v1/purge',
                method: 'POST'
            } );
            
            if ( ! response || response.success === false ) {
                throw new Error( response?.message || __( 'The cleanup request was not completed.', 'cybermaps' ) );
            }
            
            const message = summarizePurge( response );
            setFeedback( { status: 'success', message } );
            setIsModalOpen( false );
            announce( message );
        } catch ( e ) {
			const message = e && e.message
				? e.message
				: __( 'Could not complete the static-file cleanup request.', 'cybermaps' );
			setFeedback( { status: 'error', message } );
			setIsModalOpen( false );
			announce( message );
		} finally {
			setIsPurging( false );
        }
    };

    return el( Fragment, null,
		feedback && el( Notice, {
			status: feedback.status,
			isDismissible: true,
			onRemove: () => setFeedback( null ),
		}, feedback.message ),
        el( Button, {
            isDestructive: true,
            variant: 'secondary',
            onClick: () => setIsModalOpen( true ),
            style: { color: '#d63638', borderColor: '#d63638' }
        }, __( 'Remove Core-Owned Static Files', 'cybermaps' ) ),
        el( 'p', { className: 'description' }, 
            __( 'Deletes only unchanged files that Cybermaps can verify it created. Pre-existing or edited files are retained and reported. Files return on a later content or settings sync while static delivery is enabled.', 'cybermaps' )
        ),
        isModalOpen && el( Modal, {
            title: __( 'Confirm Static-File Cleanup', 'cybermaps' ),
            onRequestClose: () => setIsModalOpen( false )
        },
            el( 'p', null, __( 'Remove every unchanged static file in the Cybermaps ownership inventory?', 'cybermaps' ) ),
            el( 'p', null, el( 'strong', null, __( 'Edited and pre-existing files will not be deleted.', 'cybermaps' ) ) ),
            el( 'div', { style: { display: 'flex', gap: '10px', justifyContent: 'flex-end', marginTop: '20px' } },
                el( Button, { variant: 'secondary', onClick: () => setIsModalOpen( false ) }, __( 'Cancel', 'cybermaps' ) ),
                el( Button, { 
                    isPrimary: true, 
                    isDestructive: true, 
                    onClick: handlePurge, 
                    disabled: isPurging 
                }, isPurging ? el( Spinner ) : __( 'Remove Owned Files', 'cybermaps' ) )
            )
        )
    );
};

/**
 * Media Upload Component
 */
const MediaUpload = ( { target, initialValue } ) => {
    const [ value, setValue ] = useState( initialValue );

    const openFrame = ( e ) => {
        e.preventDefault();
        const frame = wp.media( {
            title: __( 'Select Agency Logo', 'cybermaps' ),
            button: { text: __( 'Use this logo', 'cybermaps' ) },
            library: { type: 'image' },
            multiple: false
        } );

        frame.on( 'select', () => {
            const attachment = frame.state().get( 'selection' ).first().toJSON();
            setValue( attachment.url );
            const input = document.querySelector( target );
            if ( input ) {
                input.value = attachment.url;
                input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
            }
        } );

        frame.open();
    };

    const handleClear = () => {
        setValue( '' );
        const input = document.querySelector( target );
        if ( input ) {
            input.value = '';
            input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
        }
    };

    return el( 'div', { className: 'cm-media-react-wrapper' },
        el( 'div', { style: { display: 'flex', gap: '10px', alignItems: 'center' } },
            el( Button, { variant: 'secondary', onClick: openFrame }, __( 'Select Logo', 'cybermaps' ) ),
            value && el( Button, { variant: 'link', isDestructive: true, onClick: handleClear }, __( 'Clear', 'cybermaps' ) )
        ),
        value && el( 'div', { 
            className: 'cm-media-preview', 
            style: { marginTop: '15px', padding: '10px', background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: '4px', display: 'inline-block' } 
        },
            el( 'img', { src: value, alt: '', style: { maxHeight: '60px', maxWidth: '200px', display: 'block', objectFit: 'contain' } } )
        ),
        value !== initialValue && el( Notice, { status: 'warning', isDismissible: false, style: { marginTop: '10px' } },
            el( 'p', null, __( 'Logo selected. Please click Save Changes below to apply.', 'cybermaps' ) )
        )
    );
};

/**
 * Exchange Hub Component
 */
const ExchangeHub = () => {
    const maxImportBytes = Number( cybermaps_discovery.exchange_max_import_bytes ) || 1048576;
    const [ importMode, setImportMode ] = useState( 'merge' );
    const [ isExporting, setIsExporting ] = useState( false );
    const [ isPreviewing, setIsPreviewing ] = useState( false );
    const [ isImporting, setIsImporting ] = useState( false );
    const [ fileContent, setFileContent ] = useState( '' );
    const [ fileKind, setFileKind ] = useState( '' );
    const [ fileError, setFileError ] = useState( '' );
    const [ fileSummary, setFileSummary ] = useState( '' );
    const [ preview, setPreview ] = useState( null );
    const [ previewError, setPreviewError ] = useState( '' );
    const [ highImpactAcknowledged, setHighImpactAcknowledged ] = useState( false );
	const [ confirmReplace, setConfirmReplace ] = useState( false );
	const [ importSuccess, setImportSuccess ] = useState( '' );

    const resetPreview = () => {
        setPreview( null );
        setPreviewError( '' );
        setHighImpactAcknowledged( false );
		setConfirmReplace( false );
		setImportSuccess( '' );
    };

    const messages = ( value ) => Array.isArray( value ) ? value : [];
    const messageText = ( item ) => {
        if ( typeof item === 'string' ) return item;
        if ( item && typeof item === 'object' ) {
            return item.message || item.label || item.field || __( 'Configuration issue', 'cybermaps' );
        }
        return String( item ?? '' );
    };
    const displayValue = ( value ) => {
        if ( typeof value === 'undefined' ) return __( 'Not provided', 'cybermaps' );
        if ( value === null ) return 'null';
        if ( value === '' ) return __( '(empty)', 'cybermaps' );
        if ( typeof value === 'boolean' ) return value ? 'true' : 'false';

        let rendered;
        if ( typeof value === 'string' ) {
            rendered = value;
        } else {
            try {
                rendered = JSON.stringify( value );
            } catch ( error ) {
                rendered = String( value );
            }
        }
        if ( rendered.length <= 500 ) return rendered;

        return el( 'details', { className: 'cm-config-long-value' },
            el( 'summary', null,
                sprintf(
                    /* translators: %d: number of characters in the configuration value. */
                    __( '%d characters — expand to review the complete value', 'cybermaps' ),
                    rendered.length
                )
            ),
            el( 'pre', null, rendered )
        );
    };

    const handleExport = ( includeValues ) => {
        setIsExporting( true );
        const baseUrl = cybermaps_discovery.exchange_export_url || '';
        if ( ! baseUrl ) {
			const message = __( 'The export URL is unavailable. Reload this page and try again.', 'cybermaps' );
			setPreviewError( message );
			announce( message );
            setIsExporting( false );
            return;
        }

        const element = document.createElement( 'a' );
        element.href = `${baseUrl}${baseUrl.includes( '?' ) ? '&' : '?'}include_values=${includeValues ? '1' : '0'}`;
        element.style.display = 'none';
        document.body.appendChild( element );
        element.click();
        document.body.removeChild( element );
        window.setTimeout( () => setIsExporting( false ), 750 );
    };

    const handleFileChange = ( e ) => {
        const file = e.target.files[ 0 ];
        setFileContent( '' );
        setFileKind( '' );
        setFileError( '' );
        setFileSummary( '' );
        setImportMode( 'merge' );
        resetPreview();
        if ( ! file ) return;

        if ( file.size > maxImportBytes ) {
            setFileError( __( 'The selected file exceeds the 1 MB import limit.', 'cybermaps' ) );
            e.target.value = '';
            return;
        }

        const reader = new FileReader();
        reader.onload = ( event ) => {
            const content = typeof event.target.result === 'string' ? event.target.result : '';
            const normalized = content.replace( /^\uFEFF/, '' );
            setFileContent( content );

            if ( normalized.trimStart().startsWith( '{' ) ) {
                try {
                    const payload = JSON.parse( normalized );
                    if ( payload?.format === 'cybermaps-configuration-backup' && payload?.format_version === 1 ) {
                        if (
                            ! payload.configuration
                            || typeof payload.configuration !== 'object'
                            || Array.isArray( payload.configuration )
                        ) {
                            throw new Error( __( 'The Cybermaps backup is missing its configuration payload.', 'cybermaps' ) );
                        }
                        if ( typeof payload.checksum !== 'string' || ! payload.checksum.startsWith( 'sha256:' ) ) {
                            throw new Error( __( 'The Cybermaps backup is missing its integrity value.', 'cybermaps' ) );
                        }

                        setFileKind( 'backup' );
                        const source = typeof payload.source_site === 'string'
                            ? payload.source_site.replace( /\s+/g, ' ' ).slice( 0, 200 )
                            : __( 'unknown site', 'cybermaps' );
                        const generated = typeof payload.generated_gmt === 'string'
                            ? payload.generated_gmt.replace( /\s+/g, ' ' ).slice( 0, 64 )
                            : __( 'unknown time', 'cybermaps' );
                        setFileSummary(
                            __( 'Cybermaps JSON backup detected. Its integrity and completeness will be verified by the server before any settings change.', 'cybermaps' )
                            + ` ${__( 'Source:', 'cybermaps' )} ${source} · ${__( 'Generated:', 'cybermaps' )} ${generated}`
                        );
                        return;
                    }

                    if ( payload?.format === 'cybermaps-ai-configuration-changes' && payload?.format_version === 2 ) {
                        if ( ! payload.changes || typeof payload.changes !== 'object' ) {
                            throw new Error( __( 'The AI changes envelope does not contain a changes object.', 'cybermaps' ) );
                        }
                        setFileKind( 'brief-v2' );
                        setFileSummary( __( 'Cybermaps AI Configuration Brief changes detected. Cybermaps will validate every field and show the sanitized result before anything is applied.', 'cybermaps' ) );
                        return;
                    }

                    throw new Error( __( 'This JSON file is not a supported Cybermaps backup or AI changes envelope.', 'cybermaps' ) );
                } catch ( error ) {
                    setFileContent( '' );
                    setFileError( error?.message || __( 'The JSON configuration file could not be read.', 'cybermaps' ) );
                    return;
                }
            }

            const isV2Brief = /CYBERMAPS-AI-(?:CONFIGURATION-|CONFIG-)?BRIEF:\s*2/i.test( normalized )
                || /"format"\s*:\s*"cybermaps-ai-configuration-changes"/i.test( normalized );
            if ( isV2Brief ) {
                setFileKind( 'brief-v2' );
                setFileSummary( __( 'Cybermaps AI Configuration Brief detected. Cybermaps will validate the embedded changes and show the sanitized result before anything is applied.', 'cybermaps' ) );
                return;
            }

            setFileContent( '' );
            setFileError( __( 'Use a complete Cybermaps JSON backup or a version 2 AI Configuration Brief. Earlier Markdown templates are no longer supported.', 'cybermaps' ) );
        };
        reader.onerror = () => {
            setFileContent( '' );
            setFileError( __( 'The selected file could not be read.', 'cybermaps' ) );
        };
        reader.readAsText( file );
    };

    const handlePreview = async () => {
        if ( ! fileContent ) {
			const message = __( 'Please select a Cybermaps JSON backup or version 2 AI Configuration Brief.', 'cybermaps' );
			setFileError( message );
			announce( message );
            return;
        }

        resetPreview();
        setIsPreviewing( true );
		try {
			const response = await postAdminAjax( {
				action: 'cybermaps_preview_config',
				nonce: cybermaps_discovery.exchange_nonce,
				configuration: fileContent,
				mode: importMode,
			} );
            if ( response.success && response.data?.preview ) {
                setPreview( response.data.preview );
                setHighImpactAcknowledged( false );
                return;
            }

            setPreviewError( response.data?.message || __( 'The server did not return a usable preview.', 'cybermaps' ) );
		} catch ( error ) {
			const message = error?.data?.message || error?.message
                || __( 'Could not complete the preview request.', 'cybermaps' );
            setPreviewError( message );
			announce( message );
		} finally {
            setIsPreviewing( false );
		}
    };

    const previewErrors = messages( preview?.errors );
    const previewWarnings = messages( preview?.warnings );
    const previewChanges = messages( preview?.changes );
    const highImpactChanges = messages( preview?.high_impact_changes );
    const effectiveChanges = previewChanges.filter( change => ! change || typeof change !== 'object' || change.status !== 'unchanged' );
    const unchangedChanges = previewChanges.filter( change => change && typeof change === 'object' && change.status === 'unchanged' );
    const hasHighImpact = highImpactChanges.length > 0
        || effectiveChanges.some( change => change && typeof change === 'object' && change.high_impact );
    const groupedChanges = effectiveChanges.reduce( ( groups, change ) => {
        const section = change && typeof change === 'object' && change.section
            ? String( change.section )
            : __( 'Configuration', 'cybermaps' );
        if ( ! groups[ section ] ) groups[ section ] = [];
        groups[ section ].push( change );
        return groups;
    }, {} );

    const applyImport = async () => {
		setConfirmReplace( false );
		setPreviewError( '' );
		setImportSuccess( '' );
		setIsImporting( true );
		try {
			const response = await postAdminAjax( {
				action: 'cybermaps_import_config',
				nonce: cybermaps_discovery.exchange_nonce,
				configuration: fileContent,
				mode: importMode,
				content_hash: preview.content_hash,
				configuration_hash: preview.configuration_hash,
				acknowledge_high_impact: highImpactAcknowledged ? '1' : '0',
			} );
			if ( ! response.success ) {
				throw new Error( response.data?.message || __( 'Unknown import error.', 'cybermaps' ) );
			}

			const message = response.data?.message || __( 'Cybermaps configuration was imported.', 'cybermaps' );
			setImportSuccess( message );
			announce( message );
		} catch ( error ) {
			const message = error?.data?.message || error?.message
				|| __( 'Could not complete the import request.', 'cybermaps' );
			setPreviewError( message );
			announce( message );
		} finally {
			setIsImporting( false );
		}
	};

    const handleImport = () => {
        if ( ! preview || ! preview.content_hash || ! preview.configuration_hash ) {
            setPreviewError( __( 'Preview this file before applying it.', 'cybermaps' ) );
            return;
        }
        if ( previewErrors.length > 0 ) {
            setPreviewError( __( 'Resolve the preview errors before applying this configuration.', 'cybermaps' ) );
            return;
        }
        if ( hasHighImpact && ! highImpactAcknowledged ) {
            setPreviewError( __( 'Acknowledge the high-impact changes before applying this configuration.', 'cybermaps' ) );
            return;
        }

		if ( fileKind === 'backup' && importMode === 'overwrite' ) {
			setConfirmReplace( true );
            return;
        }

		applyImport();
    };

    const isExactBackup = fileKind === 'backup';
    const canPreview = Boolean(
        fileContent
        && ! fileError
    );
    const canImport = Boolean(
        preview
        && preview.content_hash
        && preview.configuration_hash
        && previewErrors.length === 0
        && effectiveChanges.length > 0
        && ( ! hasHighImpact || highImpactAcknowledged )
    );

    return el( Fragment, null,
		el( 'div', { className: 'cm-exchange-wrapper' },
			el( 'div', { className: 'cm-exchange-grid' },
            el( 'div', { className: 'cm-exchange-col' },
                el( 'div', { className: 'cm-exchange-header' },
                    el( 'span', { className: 'dashicons dashicons-download' } ),
                    el( 'h4', null, __( 'Backup & AI Configuration Brief', 'cybermaps' ) )
                ),
                el( 'p', { className: 'description' }, __( 'Download a complete, versioned JSON backup of this site’s Cybermaps configuration, including nested values and the private Cybermaps REST API secret.', 'cybermaps' ) ),
                el( 'p', { className: 'description' }, __( 'Site configuration backups do not include network-wide settings, cross-site translation relationships, analytics history, saved report runs, WordPress content or media, or generated static files. Store backups securely.', 'cybermaps' ) ),
                el( Notice, { status: 'warning', isDismissible: false, className: 'cm-exchange-secret-warning' },
                    el( 'p', null, el( 'strong', null, __( 'Keep the complete JSON backup private.', 'cybermaps' ) ), ' ', __( 'It can contain the Cybermaps REST API secret and information entered in plugin settings.', 'cybermaps' ) )
                ),
                el( 'div', { className: 'cm-exchange-actions' },
                    el( Button, { 
                        isPrimary: true, 
                        onClick: () => handleExport( true ), 
                        disabled: isExporting,
                        style: { width: '100%', justifyContent: 'center', marginBottom: '10px' } 
                    }, isExporting ? el( Spinner ) : __( 'Download Backup', 'cybermaps' ) ),
                    el( 'p', { className: 'cm-ai-brief-disclosure' },
                        __( 'The AI Configuration Brief includes current non-secret settings—such as publication guidance, external URLs, report branding, crawler choices, and configured public identity/contact/catalog context—so an AI can make grounded recommendations. Review it before sharing. The private Cybermaps REST API secret and IndexNow key are excluded.', 'cybermaps' )
                    ),
                    el( Button, {
                        variant: 'secondary',
                        onClick: () => handleExport( false ),
                        disabled: isExporting,
                        className: 'cm-exchange-brief-button'
                    },
                        el( 'span', { className: 'dashicons dashicons-welcome-learn-more' } ),
                        __( 'Download AI Configuration Brief', 'cybermaps' )
                    )
                )
            ),
            el( 'div', { className: 'cm-exchange-col' },
                el( 'div', { className: 'cm-exchange-header' },
                    el( 'span', { className: 'dashicons dashicons-upload' } ),
                    el( 'h4', null, __( 'Review & Import Configuration', 'cybermaps' ) )
                ),
                el( 'div', { className: 'cm-import-zone' },
                    el( 'input', { 
                        type: 'file', 
                        id: 'cybermaps-import-file-hidden', 
                        accept: '.json,.md,.cyberconf.md,application/json,text/markdown',
                        className: 'cm-file-input',
                        'aria-label': __( 'Cybermaps configuration file', 'cybermaps' ),
                        disabled: isPreviewing || isImporting,
                        onChange: handleFileChange
                    } ),
                    fileError && el( Notice, { status: 'error', isDismissible: false },
                        el( 'p', null, fileError )
                    ),
                    fileSummary && el( Notice, { status: 'info', isDismissible: false },
                        el( 'p', null, fileSummary )
                    ),
                    fileContent && el( 'div', { className: 'cm-import-modes' },
                        el( RadioControl, {
                            label: __( 'Import mode', 'cybermaps' ),
                            hideLabelFromVision: true,
                            selected: importMode,
                            disabled: isPreviewing || isImporting,
                            options: isExactBackup
                                ? [
                                    { label: __( 'Smart Merge — apply backup values and preserve destination-only settings', 'cybermaps' ), value: 'merge' },
                                    { label: __( 'Full Replace — restore the complete backed-up configuration', 'cybermaps' ), value: 'overwrite' },
                                ]
                                : [
                                    { label: __( 'Smart Merge — apply selected non-null template values', 'cybermaps' ), value: 'merge' },
                                ],
                            onChange: ( value ) => {
                                setImportMode( value );
                                resetPreview();
                            }
                        } )
                    ),
                    previewError && el( Notice, { status: 'error', isDismissible: false },
                        el( 'p', null, previewError )
                    ),
					importSuccess && el( Notice, {
						status: 'success',
						isDismissible: false,
					},
						el( 'p', null, importSuccess ),
						el( Button, { variant: 'secondary', onClick: () => window.location.reload() }, __( 'Reload Settings', 'cybermaps' ) )
					),
                    el( Button, {
                        variant: 'secondary',
                        onClick: handlePreview,
                        disabled: isPreviewing || isImporting || ! canPreview,
                        'aria-busy': isPreviewing,
                        'aria-label': isPreviewing ? __( 'Previewing configuration changes', 'cybermaps' ) : undefined,
                        className: 'cm-preview-button'
                    }, isPreviewing ? el( Spinner ) : ( preview ? __( 'Refresh Preview', 'cybermaps' ) : __( 'Preview Changes', 'cybermaps' ) ) ),
                    preview && el( 'section', { className: 'cm-config-preview', 'aria-labelledby': 'cybermaps-config-preview-title' },
                        el( 'div', { className: 'cm-config-preview-header' },
                            el( 'div', null,
                                el( 'span', { className: 'cm-config-preview-kicker' }, __( 'Server-validated preview', 'cybermaps' ) ),
                                el( 'h5', { id: 'cybermaps-config-preview-title' }, __( 'Review the exact sanitized result', 'cybermaps' ) )
                            ),
                            el( 'span', { className: 'cm-config-preview-count', role: 'status', 'aria-live': 'polite' },
                                sprintf(
                                    /* translators: %d: number of configuration changes. */
                                    _n( '%d change', '%d changes', effectiveChanges.length, 'cybermaps' ),
                                    effectiveChanges.length
                                )
                            )
                        ),
                        previewErrors.length > 0 && el( Notice, { status: 'error', isDismissible: false },
                            el( 'strong', null, __( 'Errors — application is blocked', 'cybermaps' ) ),
                            el( 'ul', null, previewErrors.map( ( item, index ) => el( 'li', { key: `error-${index}` }, messageText( item ) ) ) )
                        ),
                        previewWarnings.length > 0 && el( Notice, { status: 'warning', isDismissible: false },
                            el( 'strong', null, __( 'Warnings', 'cybermaps' ) ),
                            el( 'ul', null, previewWarnings.map( ( item, index ) => el( 'li', { key: `warning-${index}` }, messageText( item ) ) ) )
                        ),
                        hasHighImpact && el( 'div', { className: 'cm-config-high-impact' },
                            el( 'h6', null, __( 'High-impact changes', 'cybermaps' ) ),
                            el( 'p', null, __( 'These settings can change public routing, crawler access, identity, or external publication behavior. Review them carefully.', 'cybermaps' ) ),
                            highImpactChanges.length > 0 && el( 'ul', null,
                                highImpactChanges.map( ( item, index ) => el( 'li', { key: `impact-${index}` }, messageText( item ) ) )
                            ),
                            el( CheckboxControl, {
                                label: __( 'I reviewed and acknowledge these high-impact changes.', 'cybermaps' ),
                                checked: highImpactAcknowledged,
                                disabled: isImporting,
                                onChange: setHighImpactAcknowledged
                            } )
                        ),
                        effectiveChanges.length === 0 && previewErrors.length === 0 && el( Notice, { status: 'info', isDismissible: false },
                            el( 'p', null, __( 'No configuration changes would be made.', 'cybermaps' ) )
                        ),
                        Object.entries( groupedChanges ).map( ( [ section, changes ] ) =>
                            el( 'section', { className: 'cm-config-preview-group', key: section },
                                el( 'h6', null, section ),
                                el( 'div', { className: 'cm-config-change-list' },
                                    changes.map( ( change, index ) => {
                                        const row = change && typeof change === 'object' ? change : {};
                                        const label = row.label || row.field || __( 'Configuration value', 'cybermaps' );
                                        return el( 'article', { className: `cm-config-change${row.high_impact ? ' is-high-impact' : ''}`, key: `${row.field || 'change'}-${index}` },
                                            el( 'div', { className: 'cm-config-change-title' },
                                                el( 'strong', null, label ),
                                                row.field && row.label && el( 'code', null, row.field ),
                                                row.status && el( 'span', { className: 'cm-config-change-status' }, String( row.status ) ),
                                                row.high_impact && el( 'span', { className: 'cm-config-change-risk' }, __( 'High impact', 'cybermaps' ) )
                                            ),
                                            el( 'dl', null,
                                                el( 'div', null, el( 'dt', null, __( 'Current', 'cybermaps' ) ), el( 'dd', null, displayValue( row.before ) ) ),
                                                el( 'div', null, el( 'dt', null, __( 'Proposed', 'cybermaps' ) ), el( 'dd', null, displayValue( row.proposed ) ) ),
                                                el( 'div', null, el( 'dt', null, __( 'Final sanitized value', 'cybermaps' ) ), el( 'dd', null, displayValue( row.final ) ) )
                                            )
                                        );
                                    } )
                                )
                            )
                        ),
                        unchangedChanges.length > 0 && el( 'details', { className: 'cm-config-unchanged' },
                            el( 'summary', null,
                                sprintf(
                                    /* translators: %d: number of reviewed configuration values that are unchanged. */
                                    _n(
                                        '%d reviewed value is already current',
                                        '%d reviewed values are already current',
                                        unchangedChanges.length,
                                        'cybermaps'
                                    ),
                                    unchangedChanges.length
                                )
                            ),
                            el( 'ul', null, unchangedChanges.map( ( change, index ) => {
                                const row = change && typeof change === 'object' ? change : {};
                                return el( 'li', { key: `unchanged-${row.field || index}` },
                                    el( 'strong', null, row.label || row.field || __( 'Configuration value', 'cybermaps' ) ),
                                    row.field && row.label && el( 'code', null, row.field ),
                                    el( 'div', { className: 'cm-config-unchanged-value' }, displayValue( row.final ) )
                                );
                            } ) )
                        ),
                        el( 'p', { className: 'cm-config-preview-fingerprint' },
                            __( 'If the file or site configuration changes, Cybermaps will reject this preview and require a new one.', 'cybermaps' )
                        ),
                        el( Button, {
                            variant: 'primary',
                            onClick: handleImport,
                            disabled: isImporting || ! canImport,
                            'aria-busy': isImporting,
                            'aria-label': isImporting ? __( 'Applying reviewed configuration changes', 'cybermaps' ) : undefined,
                            className: 'cm-apply-preview-button'
                        }, isImporting ? el( Spinner ) : __( 'Apply Reviewed Changes', 'cybermaps' ) )
                    )
                )
			)
		),
		),
		confirmReplace && el( Modal, {
			title: __( 'Confirm Full Replace', 'cybermaps' ),
			onRequestClose: () => setConfirmReplace( false ),
		},
			el( 'p', null, __( 'Apply this reviewed Full Replace? Network settings, translation relationships, analytics history, report runs, and WordPress content and media are not replaced. Generated publications may be reconciled from the restored settings.', 'cybermaps' ) ),
			el( 'div', { className: 'cm-confirm-actions' },
				el( Button, { variant: 'secondary', onClick: () => setConfirmReplace( false ) }, __( 'Cancel', 'cybermaps' ) ),
				el( Button, { variant: 'primary', isDestructive: true, onClick: applyImport }, __( 'Apply Full Replace', 'cybermaps' ) )
			)
		)
    );
};

/**
 * Shared accessible confirmation dialog for server-rendered destructive links.
 */
const ConfirmationController = () => {
	const [ pending, setPending ] = useState( null );

	useEffect( () => {
		const requestConfirmation = ( event ) => {
			if ( ! ( event.target instanceof window.Element ) ) return;
			const target = event.target.closest( '[data-cybermaps-confirm]' );
			if ( ! target || target.dataset.cybermapsConfirmBypass === '1' ) return;

			event.preventDefault();
			setPending( {
				target,
				message: target.dataset.cybermapsConfirm || __( 'Continue with this action?', 'cybermaps' ),
			} );
		};
		document.addEventListener( 'click', requestConfirmation );
		document.addEventListener( 'submit', requestConfirmation );

		return () => {
			document.removeEventListener( 'click', requestConfirmation );
			document.removeEventListener( 'submit', requestConfirmation );
		};
	}, [] );

	if ( ! pending ) return null;

	const proceed = () => {
		const target = pending.target;
		setPending( null );
		if ( target instanceof window.HTMLFormElement ) {
			target.dataset.cybermapsConfirmBypass = '1';
			target.requestSubmit();
			return;
		}
		if ( target instanceof window.HTMLAnchorElement ) {
			window.location.assign( target.href );
		}
	};

	return el( Modal, {
		title: __( 'Confirm Action', 'cybermaps' ),
		onRequestClose: () => setPending( null ),
	},
		el( 'p', null, pending.message ),
		el( 'div', { className: 'cm-confirm-actions' },
			el( Button, { variant: 'secondary', onClick: () => setPending( null ) }, __( 'Cancel', 'cybermaps' ) ),
			el( Button, { variant: 'primary', isDestructive: true, onClick: proceed }, __( 'Continue', 'cybermaps' ) )
		)
	);
};

/**
 * Initialization
 */
document.addEventListener( 'DOMContentLoaded', () => {
    // Mount page-specific interactive components when their server-rendered
    // tab contains the corresponding root.
    mount( document.getElementById( 'cybermaps-nuclear-purge-root' ), el( NuclearPurge ) );
    mount( document.getElementById( 'cybermaps-exchange-root' ), el( ExchangeHub ) );
	const confirmationRoot = document.createElement( 'div' );
	confirmationRoot.id = 'cybermaps-confirmation-root';
	document.body.appendChild( confirmationRoot );
	mount( confirmationRoot, el( ConfirmationController ) );

    const mediaRoots = document.querySelectorAll( '.cybermaps-media-button-root' );
    mediaRoots.forEach( mRoot => {
        mount( mRoot, el( MediaUpload, {
            target: mRoot.dataset.target,
            initialValue: mRoot.dataset.value
        } ) );
    } );

    // Hash-link scroll handler: compensates for the WP admin bar and tab nav.
    const scrollToHash = () => {
        const hash = window.location.hash.substring( 1 );
        if ( ! hash ) return;
        let targetId;
        try {
            targetId = decodeURIComponent( hash );
        } catch ( error ) {
            return;
        }
        const target = document.getElementById( targetId );
        if ( ! target ) return;
        requestAnimationFrame( () => {
            const adminBar = document.getElementById( "wpadminbar" );
            const offset = ( adminBar ? adminBar.offsetHeight : 0 ) + 60;
            const top = target.getBoundingClientRect().top + window.pageYOffset - offset;
            window.scrollTo( { top, behavior: "smooth" } );
        } );
    };
    scrollToHash();
    window.addEventListener( "hashchange", scrollToHash );

    document.addEventListener( 'input', ( event ) => {
        if ( ! event.target.matches( '.cm-range-slider' ) ) {
            return;
        }

        const value = event.target.nextElementSibling;
        if ( value && value.classList.contains( 'cm-slider-value' ) ) {
            value.textContent = event.target.value;
        }
    } );
} );

// Sync the active tab into the hidden form field before save
// so the redirect lands back on the correct tab.
document.addEventListener( 'DOMContentLoaded', function() {
    var form = document.getElementById( 'cybermaps-settings-form' );
    if ( ! form ) return;

    /*
     * Collapse a bracket-named structured option into one JSON input. This
     * keeps the crawler matrix and Identity Hub from consuming hundreds of
     * PHP input variables while preserving the ordinary named controls as a
     * no-JavaScript fallback.
     */
    var serializeStructuredOption = function( optionName, payloadId ) {
        var controls = Array.from(
            form.querySelectorAll( '[name^="' + optionName + '["]' )
        );
        var payload = {};

        var assign = function( target, path, value, append ) {
            var cursor = target;
            path.forEach( function( key, index ) {
                var last = index === path.length - 1;
                if ( last ) {
                    if ( append ) {
                        if ( ! Array.isArray( cursor[ key ] ) ) {
                            cursor[ key ] = [];
                        }
                        cursor[ key ].push( value );
                    } else {
                        cursor[ key ] = value;
                    }
                    return;
                }
                if ( ! cursor[ key ] || typeof cursor[ key ] !== 'object' ) {
                    cursor[ key ] = {};
                }
                cursor = cursor[ key ];
            } );
        };

        controls.forEach( function( control ) {
            if (
                control.disabled
                || control.closest( '#cybermaps-page-dropdown-template' )
                || ( ( control.type === 'checkbox' || control.type === 'radio' ) && ! control.checked )
            ) {
                return;
            }

            var path = [];
            control.name.replace( /\[([^\]]*)\]/g, function( match, key ) {
                path.push( key );
                return match;
            } );
            var append = path.length > 0 && path[ path.length - 1 ] === '';
            if ( append ) {
                path.pop();
            }
            if ( ! path.length || path.some( function( key ) { return key === ''; } ) ) {
                return;
            }

            if ( control.tagName === 'SELECT' && control.multiple ) {
                var selectedValues = Array.from( control.selectedOptions ).map( function( option ) {
                    return option.value;
                } );
                if ( append ) {
                    selectedValues.forEach( function( selectedValue ) {
                        assign( payload, path, selectedValue, true );
                    } );
                } else {
                    assign( payload, path, selectedValues, false );
                }
                return;
            }
            assign( payload, path, control.value, append );
        } );

        var hidden = form.querySelector( '#' + payloadId );
        if ( ! hidden ) return;
        hidden.name = optionName;
        hidden.value = JSON.stringify( payload );
        hidden.disabled = false;
        controls.forEach( function( control ) {
            if ( control.form === form ) {
                control.disabled = true;
            }
        } );
    };

    form.addEventListener( 'submit', function() {
        var field = form.querySelector( 'input[name="cybermaps_active_tab"]' );
        var panel  = document.querySelector( '.cybermaps-tab-content.active' );
        if ( field && panel ) {
            field.value = panel.id.replace( 'cybermaps-tab-', '' );
        }

        if ( panel && panel.id === 'cybermaps-tab-schema' ) {
            serializeStructuredOption( 'cybermaps_identity_data', 'cybermaps-identity-json-payload' );
        }

        if ( panel && panel.id === 'cybermaps-tab-ai' ) {
            serializeStructuredOption( 'cybermaps_robots_manager', 'cybermaps-robots-json-payload' );
        }

    } );
} );
