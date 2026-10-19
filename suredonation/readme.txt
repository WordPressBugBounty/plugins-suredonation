=== SureDonation - Donation Forms, Fundraising Campaigns & Donor Management ===
Contributors: brainstormforce
Donate link: https://www.paypal.me/BrainstormForce
Tags: donation, donation form, fundraising, nonprofit, recurring donations
Requires at least: 6.4
Tested up to: 7.1.2
Stable tag: 1.7.0
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept online donations with fundraising campaigns, donation forms, donor management, Stripe, PayPal, and offline payments.

== Description ==

SureDonation is a WordPress donation plugin and fundraising plugin for nonprofits, charities, schools, religious organizations, creators, and fundraising teams. Use it as a donation form builder to accept online donations through Stripe, PayPal, or offline payments, create fundraising campaigns, manage donors, recover fees, and migrate from GiveWP or Charitable.

👉 <a href="https://bsf.io/suredonation-demo" target="_blank" rel="">Try the live demo of SureDonation</a>

= Why SureDonation? =

* **Native block editor**: Campaigns and donation forms are built with WordPress blocks. No external form builder needed.
* **Campaign-based fundraising**: Create campaigns with goals, progress tracking, donor lists, stats, and dedicated campaign pages, or start from one of 8 ready-made campaign templates.
* **Flexible donation forms**: Add preset amounts with descriptions, custom amounts, donor fields, donor comments, anonymous donations, fee recovery, and styling controls.
* **Payment options**: Accept donations through Stripe, PayPal, or offline methods such as bank transfer or check. Recurring donations are available with SureDonation Pro.
* **Spam protection and validation**: Use Honeypot protection, client-side validation, server-side validation, and customizable validation messages.
* **Donor management**: Track donors, donation history, totals, statuses, imports, and exports from the WordPress dashboard.
* **Easy migration**: Import campaigns, donors, and donations from GiveWP or Charitable with a guided migration flow.
* **Page builder support**: Add donation forms and campaign blocks with Elementor widgets or Bricks elements.
* **AI-ready foundation**: Register campaigns, donations, donors, forms, settings, and analytics with WordPress Abilities API support, with optional MCP support where available.

= Campaign-Based Fundraising =

* Unlimited campaigns with fundraising goals and live progress.
* 8 campaign templates to start from: Animal Rescue, Buy Me a Coffee, Disaster Relief, Donate for a Cause, Education, Faith, In Memory, and Medical.
* Dedicated campaign pages built with blocks, so they can be customized like regular WordPress content.
* Campaign display blocks for goal progress, campaign stats, recent donations, donor list, donor comments, social sharing, and donate button.
* Social sharing previews for campaign pages, with Open Graph and Twitter card tags.
* Campaign statuses, featured images, and per-campaign default donation forms.

= Donation Forms in the Block Editor =

* Preset donation amounts with optional descriptions, plus custom amount input.
* Field blocks for text, email, number, phone, address, URL, dropdown, checkbox, and donor comments, with validation and input masks.
* Layout blocks for headings, images, and custom HTML.
* Anonymous donation option for donors who do not want their name shown publicly.
* Cover-fees toggle so donors can help offset processing costs.
* Privacy consent checkbox for donation forms.
* Form styling with live preview, including colors, background, spacing, and border radius.
* Theme color palette support inside form styling options.
* Duplicate forms in one click and embed them anywhere with the `[suredonation_form]` shortcode.

= Spam Protection & Validation =

* Honeypot spam protection for donation forms.
* Customizable default validation messages from global settings.
* Field-level validation for required fields, email format, number fields, length limits, and amount rules.
* Server-side validation to help prevent payment amount and field tampering.

= Payments =

* **Stripe**: Credit and debit card donations with test and live modes, and refunds from the dashboard.
* **PayPal**: Guided onboarding, sandbox and live modes, and dashboard refunds.
* **Offline donations**: Bank transfer or check donations with custom instructions and manual completion.
* 40 currencies supported.
* Fee recovery with global or per-gateway rates and server-side validation.
* Test-mode donations are kept out of public campaign totals and dashboard reports.

= Receipts & Notifications =

* Automatic email notifications for donors and site admins.
* Customizable email templates with smart tags, including any field from the donation form.
* Weekly Email Reports digest with a summary of your donation activity.
* Confirmation messages with a clean donation receipt layout.

= Donor Management =

* Automatic donor profiles with donation history, totals, and largest gift.
* Donors are linked to WordPress user accounts only after their email address is verified.
* Donor statuses, filtering, and donor detail pages with activity charts.
* Donor comments you can approve, hold, or reject from the dashboard.
* CSV import and export of donations and donors, and JSON export and import of campaigns and settings.

= Privacy =

* Integrates with the WordPress personal data export and erasure tools.
* Optional consent checkbox on donation forms.
* Data retention setting for donation records.

= Dashboard =

* Dashboard with donations-over-time chart, totals, and recent activity.
* Quick access panel and guided onboarding for first-time setup.
* Dedicated global settings with a clear Save flow.

= Migrate from GiveWP or Charitable =

Switching from GiveWP or Charitable? SureDonation includes built-in migration tools that import campaigns, donors, and donations with preview, progress tracking, and per-record resilience. You can also import GiveWP donation exports via CSV.

= Page Builders =

SureDonation works with the block editor and with Elementor and Bricks. Elementor widgets and Bricks elements are included for the donation form and every campaign display block.

= AI-Ready: Abilities API & MCP =

SureDonation registers campaigns, donations, donors, forms, settings, and analytics with the WordPress Abilities API. When supported by your WordPress setup, optional MCP support can help authorized AI clients query donation stats or manage fundraising workflows with granular permission controls.

= SureDonation Pro =

SureDonation Pro adds more for growing fundraising programs:

* **Recurring donations**: Daily, weekly, monthly, or yearly giving with Stripe and PayPal, with subscription emails and admin controls to cancel, pause, or resume.
* **Donor Dashboard**: Donors sign in with their password or an emailed link to see their donation history, download receipts, and manage their recurring donations.
* **PDF receipts**: Three receipt designs, per-form settings, and receipts attached to donor emails.
* **Multi-step forms**: Split a form into named steps with the Page Break block.
* **Gift Aid**: Collect UK Gift Aid declarations and export claims for HMRC.
* **More fields**: Date Picker and Time Picker fields.
* **Reports**: A reports screen with revenue summaries and exports.
* **Donor notes**: Keep internal notes on each donor.
* **Advanced form styling**: Border width, box shadow, and button color controls.
* **Email marketing integrations**: Send donors to ActiveCampaign, Brevo, Kit, Mailchimp, or MailerLite.
* **More migration**: Import GiveWP and Charitable subscriptions, review import history, and roll back an import.

= Who is SureDonation for? =

SureDonation is built for nonprofits, charities, NGOs, religious organizations, schools, clubs, creators, and fundraising teams that need a nonprofit donation plugin or charity donation plugin to accept donations online without adding a heavy donation management plugin.

== Installation ==

1. Upload the `suredonation` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the Plugins menu in WordPress.
3. Follow the guided setup to connect a payment method such as Stripe, PayPal, or offline donations.
4. Create your first campaign and add a donation form to your site.

== Frequently Asked Questions ==

= What payment methods are supported? =

Stripe, PayPal, and offline donations are included. Offline donations can be used for bank transfer or check payments with custom instructions.

= Can I accept donations online with SureDonation? =

Yes. SureDonation helps you accept donations online using Stripe, PayPal, or offline donation methods such as bank transfer and check payments.

= Can I use SureDonation as a donation form builder? =

Yes. SureDonation includes block-based donation forms with preset amounts, custom amounts, donor fields, styling controls, validation, and spam protection.

= Is SureDonation a WordPress fundraising plugin? =

Yes. SureDonation works as a WordPress fundraising plugin with campaigns, campaign templates, goals, progress tracking, donation forms, donor management, and campaign display blocks.

= Can I migrate from GiveWP or Charitable? =

Yes. SureDonation includes built-in migration tools for GiveWP and Charitable that import campaigns, donors, and donations with a preview step. SureDonation Pro also imports recurring subscriptions.

= Can donors cover the processing fees? =

Yes. Fee recovery is built in. You can enable a single global rate or per-gateway rates, and donors can choose to cover fees during checkout.

= Does SureDonation include spam protection? =

Yes. SureDonation includes Honeypot spam protection for donation forms.

= Can I customize validation messages? =

Yes. Global validation message settings let you customize the default messages shown on donation form fields.

= Can donors give anonymously? =

Yes. Forms can include an anonymous donation option, and anonymous donors are hidden from public donor lists.

= Can donors leave a comment? =

Yes. Add the Donor Comment field to a form, and show approved comments on campaign pages with the Donor Comments block.

= Does SureDonation send receipts? =

Yes. SureDonation sends automatic email notifications with customizable templates and smart tags. PDF receipts, including receipt downloads from the donor dashboard, are part of SureDonation Pro.

= Does it support recurring donations? =

Yes, with SureDonation Pro. Donors can choose a one-time or recurring donation, and recurring donations are supported with Stripe and PayPal.

= Does it work with Elementor or Bricks? =

Yes. SureDonation includes Elementor widgets and Bricks elements for the donation form and the campaign display blocks.

= Does it work with my theme? =

Yes. Campaigns and forms are built with native WordPress blocks and work with block-enabled themes.

== Multilingual Translations ==

SureDonation ships with built-in translations for 7 languages. No extra plugin or manual setup is required. When your WordPress site uses one of these languages, SureDonation can load the matching translation files automatically.

* German (Deutsch)
* Spanish (Español)
* French (Français)
* Italian (Italiano)
* Dutch (Nederlands)
* Polish (Polski)
* Portuguese (Português)

The plugin includes translations for admin screens, block editor controls, form labels, and user-facing messages. SureDonation is also translation-ready for additional languages.

== Screenshots ==

1. SureDonation dashboard with donations over time, totals, and recent activity.
2. Campaign management screen for creating and managing fundraising campaigns with goals and progress.
3. Campaign details screen with goal progress, donation stats, and linked donation forms.
4. Donations list with filters for campaign, status, payment method, and date.
5. Donor profiles with lifetime totals, donation history, and export options.
6. Global settings for currency, payment mode, gateways, fee recovery, PDF receipts, spam protection, validation messages, and MCP.

== External services ==

This plugin connects to external services only when the related feature or payment gateway is configured and used.

- **Stripe** -- is used for processing donation payments. [TOS](https://stripe.com/legal/ssa) and [Privacy Policy](https://stripe.com/privacy)

- **PayPal** -- is used for processing donation payments. [TOS](https://www.paypal.com/us/legalhub/useragreement-full) and [Privacy Policy](https://www.paypal.com/us/legalhub/privacy-full)

- **ipapi.co** -- When a form uses the Phone field, the visitor's IP address is sent to ipapi.co to pre-select their country code. The result is cached. [TOS](https://ipapi.co/terms/) and [Privacy Policy](https://ipapi.co/privacy/)

- **GitHub** (`raw.githubusercontent.com`) -- The PDF library used for receipts is downloaded from GitHub when you install it from the settings. No personal data is sent. [TOS](https://docs.github.com/en/site-policy/github-terms/github-terms-of-service) and [Privacy Policy](https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement)

- **SureDonation website** (`suredonation.com`) -- The admin dashboard loads the "What's New" news feed from the SureDonation website. No personal data is sent. [Privacy Policy](https://suredonation.com/privacy-policy/)

== Changelog ==

= 1.7.0 - 19th October 2026 =
* New: Added per-option icons and images to the Donation Amount field.
* New: Added an optional description under each donation amount option.
* New: Added per-field smart tags, so any form field can be inserted into emails and confirmation messages.
* New: Added a debug log that records donation failures to make payment issues easier to troubleshoot.
* New: Added an option to remove your settings and payment gateway connections when the plugin is deleted. Donations, donors and receipts are never deleted.
* Improvement: The admin dashboard, forms list and donor totals now follow the selected Test/Live payment mode.
* Improvement: Imported custom fields are keyed by their field slug, and the slug is included in the donations export.
* Fix: Donor totals now reflect the amount actually charged, including covered fees, on every gateway.
* Fix: Public campaign figures and progress bars now follow the selected Test/Live payment mode.
* Fix: Donations are no longer linked to an existing donor by an unverified email match.

= 1.6.1 - 25th September 2026 =
* New: Added a weekly Email Reports digest that emails a summary of your donation activity.
* Improvement: Minor code improvements and bug fixes.

= 1.6.0 - 15th September 2026 =
* New: Added a Donor Comments field so supporters can leave a note with their donation.
* New: Added support for the Polish Złoty (PLN) currency.

The full changelog is available [here](https://suredonation.com/whats-new/?utm_source=wordpress.org&utm_medium=whats_new).
