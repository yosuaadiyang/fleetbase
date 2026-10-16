# Proof-of-delivery storage boundary

Photo and signature capture write to `contrust_private`, rooted at
`storage/app/contrust-private`, outside the public storage symlink. The root is
inside the existing storage volume and encrypted offsite backup scope. File rows
record the actual disk. A request cannot select a public POD destination.

Protected file serialization never falls back to a public URL. A permitted
personal-token request receives a ten-minute HMAC image capability. Image
requests need no bearer header, preserving browser/native image rendering, but
every read rechecks the token, expiry, active user/membership, current company,
current order permission and driver assignment. File storage/ownership changes
invalidate the signature. Revocation or reassignment takes effect before URL
expiry. Capabilities are bearer links for their bounded lifetime; keep them out
of chat, analytics and logs. The nginx private-file location disables access
logging. Responses use private/no-store, nosniff and no-referrer headers.

The ordinary authenticated download endpoints enforce the same POD/order
boundary and use the stored disk, including when a request supplies a different
disk. Existing branding and non-POD file URL behavior is retained. This change
does **not** certify privacy of every generic upload, export or attachment.
Bootstrap keys cannot mint image URLs; the supported console/driver flow uses
personal access tokens. Other integration authentication needs a separate
authorization contract before widening image access.

The build checks exact vendor hashes before patching File, both file download
controllers and the previously patched driver order controller. `packages/core-api`
is not edited. The required isolated HTTP fixture checks storage, raw URLs,
permitted rendering, tenant/driver/role boundaries, expiry/revocation, metadata
tampering, generic downloads and signature/photo capture. It sends no external
email and refuses production databases.

Deployment must inventory existing POD first. The approved demo organization
had zero production POD before this candidate. Existing public POD on another
installation require a verified copy/hash/DB migration and removal of the old
public bytes; this source patch alone does not revoke old static URLs. Do not
claim a legacy migration occurred when the inventory was empty.
