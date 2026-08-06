/**
 * SureDonation Elementor editor behaviours.
 *
 * Backs the Donation Form widget's "Edit Form" button, which opens the selected
 * form's own editor in a new tab.
 *
 * @package SureDonation
 * @since x.x.x
 */

/* global elementor, suredonationElementorData */
document.addEventListener( 'DOMContentLoaded', function () {
	if ( typeof elementor === 'undefined' ) {
		return;
	}

	elementor.channels.editor.on( 'suredonation:form:edit', function ( view ) {
		const settings = view && view.elementSettingsModel;

		if ( ! settings ) {
			return;
		}

		// The form selector is split into one control per campaign
		// (`form_id_{id}`) plus a campaign-less fallback (`form_id`); only the
		// control matching the current campaign holds the chosen value. Mirrors
		// Donation_Form_Widget::resolve_form_id().
		const campaignId = parseInt( settings.get( 'campaign_id' ), 10 ) || 0;
		const controlKey = campaignId ? 'form_id_' + campaignId : 'form_id';
		const formId = parseInt( settings.get( controlKey ), 10 ) || 0;

		if ( ! formId ) {
			return;
		}

		const editWindow = window.open(
			suredonationElementorData.adminUrl +
				'post.php?post=' +
				formId +
				'&action=edit',
			'_blank'
		);

		if ( editWindow ) {
			editWindow.focus();
		}
	} );
} );
