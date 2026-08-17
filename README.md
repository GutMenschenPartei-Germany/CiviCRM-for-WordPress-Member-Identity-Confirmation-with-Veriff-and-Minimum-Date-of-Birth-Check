# CiviCRM-for-WordPress-Member-Identity-Confirmation-with-Veriff-and-Minimum-Date-of-Birth-Check
This is a WordPress plugin for CiviCRM to confirm the identiy of new members with Veriff and check if they have a minimum age based on the date of birth.

---

# CiviCRM Veriff Membership Verification

WordPress plugin that verifies a user's name via [Veriff](https://www.veriff.com/)
during a CiviCRM membership signup (Stripe payment), before the form can be
submitted / the subscription can be created.

## How it works

1. On the configured CiviCRM form (e.g. the contribution page with a
   membership block), a button labelled "Verify identity with Veriff" appears.
2. Clicking it creates a Veriff session server-side (`POST /v1/sessions`),
   including first/last name as `person` data. If **InitData Matching** is
   enabled in your Veriff account, Veriff automatically compares the
   submitted name with the name extracted from the ID document.
3. The user completes the Veriff flow in a popup window.
4. Veriff sends the result asynchronously to the decision webhook
   (`/wp-json/civiveriff/v1/webhook`), signed with HMAC-SHA256 using your
   shared secret. The plugin verifies the signature, stores the status and
   the name match result in its own database table, and optionally creates a
   CiviCRM activity as an audit trail.
5. The frontend polls the status and only then enables the submit button.
6. **Server-side**, `civicrm_validateForm` additionally enforces that an
   `approved` decision with a matching name exists for the contact – enabling
   the form in the browser is only UX, not a security mechanism.

## Installation

1. Copy the `civicrm-veriff-verification` folder into `wp-content/plugins/`.
2. Activate the plugin in the WP backend (this creates the
   `wp_civiveriff_sessions` table).
3. Under **Settings → CiviCRM Veriff**:
   - Enter the Veriff API key (`X-AUTH-CLIENT`) and the shared secret
     (Veriff Customer Portal → Integrations).
   - Check/adjust the API base URL (station-specific).
   - Enter the form class(es) on which the verification should appear.
4. Register the displayed webhook URL in the Veriff Customer Portal as the
   "Decision Webhook".
5. Optionally enable **InitData Matching** in Veriff so that the name
   comparison is performed by Veriff directly (recommended). Without this
   feature, the plugin compares the names itself (a simple, tolerant string
   comparison in `class-civiveriff-rest.php`).
6. In CiviCRM, optionally create an activity type "Identity Verification"
   (Administer → Option Lists → Activity Types) so that the audit log works.

## Known limitation on mobile devices

The Veriff flow is opened via `window.open()` in a popup. On desktop this
works reliably: once finished, the popup closes automatically, the original
tab with the form stays open unchanged and enables the submit button.

On some mobile browsers (especially iOS Safari), `window.open()` following an
asynchronous AJAX call is **not** treated as a popup but as normal navigation
in the same tab. In that case the user actually leaves the form page, and any
data already entered is lost when returning (CiviCRM rebuilds the form on
return). The plugin catches this with a dedicated confirmation page
(`/veriff-verification-complete/`) and offers a "Back to signup" button, but
it cannot fully prevent the data loss.

**Cleaner but more involved fix:** Instead of popup/redirect, integrate
Veriff's official Web SDK (InContext, which runs as an overlay/iframe
directly on the form page, without ever leaving it). That would fully solve
this problem on all devices – it has deliberately not been implemented in the
current state of the plugin in order to keep the scope manageable. Let me
know if you'd like that as the next step.

## Upgrade note (v1.0.0 → current version)

The first version of this plugin tied the verification to an already existing
CiviCRM `contact_id`. On a public signup page (e.g. "Become a member"),
however, no contact exists at that point – so the form was never displayed.
The current version instead works with a one-time **token** per form load; the
actual contact is linked afterwards, once the form has been submitted (via
`civicrm_postProcess`). Simply overwrite the files – the database table
migrates itself automatically on the next page load (new `token` column).

## Things you need to adapt to your setup

Marked in the code with `ANPASSEN:` ("adapt"), among others:

- **`CRM_Contribute_Form_Contribution_Main`** as the default form class –
  depending on whether you use a contribution page with a membership block, a
  standalone membership signup form, or a custom form, the actual form class
  has to be entered. The quickest way to find the class is to temporarily add
  `error_log($formName)` in `civicrm_buildForm`, or to check the CiviCRM logs
  or URL parameters (`?_qf_...`).
- **`get_form_contact_id()`**: Depending on the form flow (new vs. existing
  contact, logged in vs. guest), the source of the contact ID may vary.
- **The jQuery selector in `veriff-frontend.js`** (`findForm()`), in case your
  theme/CiviCRM template uses different form or field names.
- **The field names `first_name` / `last_name`** in the JS, in case your form
  uses different field names (e.g. with multiple contact blocks).

## Security notes

- The actual enforcement happens **server-side** in
  `CiviVeriff_CiviCRM::validateForm()` – the JS/popup is only UX.
- The webhook endpoint is publicly reachable, but is secured via the HMAC
  signature (`X-HMAC-SIGNATURE`, shared secret); without a valid signature the
  request is rejected with a 401.
- `create-session` is secured with a WP nonce per contact ID, but this does
  not prevent a logged-in user from manipulating the submitted `contact_id` in
  principle – for sensitive setups it is advisable to additionally validate
  `contact_id` server-side against the current CiviCRM/WP session.
- The plugin does not store ID document images or similar itself – these
  remain with Veriff; it only stores the status, the verified name and the raw
  decision JSON for audit purposes.

## Not included / deliberately left open

- Automatically blocking the actual Stripe payment at the payment processor
  level (in addition to form validation) – potentially useful depending on the
  desired UX, but setup-dependent.
- Localisation beyond what CiviCRM/WPML provides (texts are currently
  hard-coded in German, but are kept centrally in
  `class-civiveriff-civicrm.php` and `veriff-frontend.js`).

## Minimum age (18 years)

In addition to the name match, the plugin optionally checks (enabled by
default) whether the date of birth read by Veriff from the ID document meets a
minimum age (default: 18 years, configurable under Settings → CiviCRM Veriff).
This works entirely independently of any date-of-birth field in the form
itself – the basis is exclusively the verified date from the ID document, not
a self-declaration. If the minimum age is not met, the form is blocked in
exactly the same way as with a name mismatch (enforced server-side via
`civicrm_validateForm`).

