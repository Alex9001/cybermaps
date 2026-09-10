/**
 * Cybermaps — Sitemap Controls Metabox
 *
 * Gutenberg sidebar panel for per-post sitemap controls:
 * exclusion toggle, manual priority, and change frequency.
 */
( function( wp ) {
    const { registerPlugin } = wp.plugins;
	const { PluginDocumentSettingPanel } = wp.editPost;
	const { ToggleControl, SelectControl, RangeControl, TextControl, Button, Notice, Spinner } = wp.components;
	const { useSelect, useDispatch } = wp.data;
	const { useState } = wp.element;
    const { __, sprintf } = wp.i18n;
	const publicationPostTypes =
        window.cybermapsEditor && Array.isArray( window.cybermapsEditor.postTypes )
            ? window.cybermapsEditor.postTypes
			: [];
	const translationConfig = window.cybermapsEditor && window.cybermapsEditor.translation
		? window.cybermapsEditor.translation
		: { enabled: false };
	const legacyComponentSizing = window.cybermapsEditor
		&& [ true, 1, '1' ].includes( window.cybermapsEditor.legacyComponentSizing );
	const legacyComponentSizeProps = legacyComponentSizing
		? { __next40pxDefaultSize: true }
		: {};

    const CybermapsSitemapControls = () => {
        const postType = useSelect( ( select ) =>
            select( 'core/editor' ).getCurrentPostType()
        );

        const meta = useSelect( ( select ) =>
            select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {}
        );

        const { editPost } = useDispatch( 'core/editor' );

        if ( ! postType || ! publicationPostTypes.includes( postType ) ) {
            return null;
        }

        const excluded = meta._cybermaps_exclude_sitemap === '1';
        const aiExcluded = meta._cybermaps_exclude_ai === '1';
        const intent = meta._cybermaps_intent_override || '';
        const priority = meta._cybermaps_sitemap_priority
            ? parseFloat( meta._cybermaps_sitemap_priority )
            : 0;
        const changefreq = meta._cybermaps_sitemap_changefreq || '';

        const setMeta = ( key, value ) => {
            editPost( {
                meta: { ...meta, [key]: value },
            } );
        };

        return wp.element.createElement(
            PluginDocumentSettingPanel,
            {
                name: 'cybermaps-sitemap-controls',
                title: __( 'Cybermaps', 'cybermaps' ),
                icon: 'admin-site-alt3',
            },
            wp.element.createElement( ToggleControl, {
                label: __( 'Exclude from XML Sitemap', 'cybermaps' ),
                help: excluded
                    ? __( 'An item-level exclusion is set, so this page is omitted from Cybermaps XML sitemaps.', 'cybermaps' )
                    : __( 'No item-level XML exclusion is set. Content-group Publish and other sitemap rules still determine eligibility.', 'cybermaps' ),
                checked: excluded,
                onChange: ( value ) => setMeta( '_cybermaps_exclude_sitemap', value ? '1' : '0' ),
            } ),
            wp.element.createElement( ToggleControl, {
                label: __( 'Exclude from AI discovery', 'cybermaps' ),
                help: aiExcluded
                    ? __( 'An item-level exclusion is set, so this page is omitted from Cybermaps AI discovery publications.', 'cybermaps' )
                    : __( 'No item-level AI exclusion is set. Content-group Publish and AI publication settings still determine eligibility.', 'cybermaps' ),
                checked: aiExcluded,
                onChange: ( value ) => setMeta( '_cybermaps_exclude_ai', value ? '1' : '0' ),
            } ),
			wp.element.createElement( SelectControl, {
				...legacyComponentSizeProps,
				label: __( 'Discovery intent', 'cybermaps' ),
                help: __( 'Optionally override this item’s intent metadata in Cybermaps discovery output.', 'cybermaps' ),
                value: intent,
                options: [
                    { label: __( 'Use content-group default', 'cybermaps' ), value: '' },
                    { label: __( 'Informational', 'cybermaps' ), value: 'informational' },
                    { label: __( 'Commercial', 'cybermaps' ), value: 'transactional' },
                ],
                onChange: ( value ) => setMeta( '_cybermaps_intent_override', value ),
            } ),
            wp.element.createElement( 'hr' ),
			wp.element.createElement( RangeControl, {
				...legacyComponentSizeProps,
				label: __( 'Sitemap priority override', 'cybermaps' ),
                help: priority > 0
                    ? sprintf(
                        /* translators: %.1f: manually selected sitemap priority. */
                        __( 'Manual override: %.1f', 'cybermaps' ),
                        priority
                    )
                    : __( "Uses this content group's publication weight. Set a value to override it for this item.", 'cybermaps' ),
                value: priority,
                // The registered REST meta schema is numeric. Zero represents
                // automatic priority; an empty string fails REST validation.
                onChange: ( val ) => setMeta( '_cybermaps_sitemap_priority', val > 0 ? val : 0 ),
                min: 0,
                max: 1.0,
                step: 0.1,
                marks: [
                    { value: 0, label: __( 'Group', 'cybermaps' ) },
                    { value: 0.5 },
                    { value: 1.0, label: '1.0' },
                ],
            } ),
			wp.element.createElement( SelectControl, {
				...legacyComponentSizeProps,
				label: __( 'Change Frequency', 'cybermaps' ),
                help: changefreq
                    ? sprintf(
                        /* translators: %s: manually selected sitemap change frequency. */
                        __( 'Manual override: %s', 'cybermaps' ),
                        changefreq
                    )
                    : __( 'Uses the default weekly change frequency.', 'cybermaps' ),
                value: changefreq,
                options: [
                    { label: __( 'Default (weekly)', 'cybermaps' ), value: '' },
                    { label: __( 'Always', 'cybermaps' ), value: 'always' },
                    { label: __( 'Hourly', 'cybermaps' ), value: 'hourly' },
                    { label: __( 'Daily', 'cybermaps' ), value: 'daily' },
                    { label: __( 'Weekly', 'cybermaps' ), value: 'weekly' },
                    { label: __( 'Monthly', 'cybermaps' ), value: 'monthly' },
                    { label: __( 'Yearly', 'cybermaps' ), value: 'yearly' },
                    { label: __( 'Never', 'cybermaps' ), value: 'never' },
                ],
                onChange: ( val ) => setMeta( '_cybermaps_sitemap_changefreq', val ),
            } )
        );
	};

	const CybermapsTranslationControls = () => {
		if ( ! translationConfig.enabled ) {
			return null;
		}

		const initialState = translationConfig.state || {};
		const [ relationship, setRelationship ] = useState( {
			group_id: parseInt( initialState.group_id || 0, 10 ),
			sync_paused: Boolean( initialState.sync_paused ),
		} );
		const [ groupId, setGroupId ] = useState(
			relationship.group_id > 0 ? String( relationship.group_id ) : ''
		);
		const [ saving, setSaving ] = useState( false );
		const [ message, setMessage ] = useState( '' );
		const [ error, setError ] = useState( '' );

		const updateRelationship = ( action ) => {
			setSaving( true );
			setMessage( '' );
			setError( '' );
			wp.apiFetch( {
				path: translationConfig.path,
				method: 'POST',
				data: {
					action,
					group_id: parseInt( groupId || 0, 10 ),
				},
			} ).then( ( nextState ) => {
				const normalized = {
					group_id: parseInt( nextState.group_id || 0, 10 ),
					sync_paused: Boolean( nextState.sync_paused ),
				};
				setRelationship( normalized );
				setGroupId( normalized.group_id > 0 ? String( normalized.group_id ) : '' );
				setMessage( __( 'Translation relationship updated.', 'cybermaps' ) );
			} ).catch( ( requestError ) => {
				setError( requestError && requestError.message
					? requestError.message
					: __( 'The translation relationship could not be updated.', 'cybermaps' )
				);
			} ).finally( () => setSaving( false ) );
		};

		return wp.element.createElement(
			PluginDocumentSettingPanel,
			{
				name: 'cybermaps-translation-controls',
				title: __( 'Cybermaps International', 'cybermaps' ),
				icon: 'translation',
			},
			message ? wp.element.createElement( Notice, {
				status: 'success',
				isDismissible: true,
				onRemove: () => setMessage( '' ),
			}, message ) : null,
			error ? wp.element.createElement( Notice, {
				status: 'error',
				isDismissible: true,
				onRemove: () => setError( '' ),
			}, error ) : null,
			relationship.group_id > 0
				? wp.element.createElement(
					'p',
					null,
					__( 'Translation group:', 'cybermaps' ) + ' ' + relationship.group_id
				)
					: wp.element.createElement( TextControl, {
						...legacyComponentSizeProps,
						label: __( 'Translation group ID', 'cybermaps' ),
					help: __( 'Enter a group from another translated post, or create a new group here.', 'cybermaps' ),
					type: 'number',
					min: 1,
					value: groupId,
					onChange: setGroupId,
				} ),
			saving ? wp.element.createElement( Spinner ) : null,
			relationship.group_id > 0
				? wp.element.createElement( Button, {
					variant: 'secondary',
					isDestructive: true,
					disabled: saving,
					onClick: () => updateRelationship( 'unlink' ),
				}, __( 'Unlink and pause sync', 'cybermaps' ) )
				: wp.element.createElement(
					wp.element.Fragment,
					null,
					wp.element.createElement( Button, {
						variant: 'primary',
						disabled: saving || parseInt( groupId || 0, 10 ) < 1,
						onClick: () => updateRelationship( 'assign' ),
					}, __( 'Assign group', 'cybermaps' ) ),
					wp.element.createElement( Button, {
						variant: 'secondary',
						disabled: saving,
						onClick: () => updateRelationship( 'create' ),
					}, __( 'Create new group', 'cybermaps' ) ),
					relationship.sync_paused ? wp.element.createElement( Button, {
						variant: 'tertiary',
						disabled: saving,
						onClick: () => updateRelationship( 'resume' ),
					}, __( 'Resume automatic sync', 'cybermaps' ) ) : null
				)
		);
	};

	const CybermapsEditorPanels = () => wp.element.createElement(
		wp.element.Fragment,
		null,
		wp.element.createElement( CybermapsSitemapControls ),
		wp.element.createElement( CybermapsTranslationControls )
	);

	registerPlugin( 'cybermaps-sitemap-controls', {
		render: CybermapsEditorPanels,
        icon: 'admin-site-alt3',
    } );
} )( window.wp );
