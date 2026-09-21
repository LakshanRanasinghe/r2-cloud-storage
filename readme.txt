=== R2 Cloud Storage ===
Contributors: jhomoura
Tags: cloudflare, r2, s3, offload, media
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offload your WordPress media to Cloudflare R2 with zero egress fees. Modular add-on system for WooCommerce, LearnDash, EDD and more.

== Description ==

**R2 Cloud Storage** offloads your WordPress Media Library to [Cloudflare R2](https://www.cloudflare.com/products/r2/) — an S3-compatible object storage with **zero egress fees**.

= Why Cloudflare R2? =

* **$0 egress fees** — No cost for serving files, unlike AWS S3 ($0.09/GB)
* S3-compatible API — Works with existing tools
* Cloudflare CDN integration — Custom domains with global edge caching
* Pay only for storage: $0.015/GB/month

= Core Features (Free) =

* Automatic media offload on upload
* URL rewriting (serve from R2/custom domain)
* Pre-signed URLs for protected content
* Bulk sync/migration tool
* Responsive image (srcset) support
* Remove local copies to save disk space
* REST API for programmatic access
* i18n ready (pt_BR included)

= Modular Add-on System =

Extend R2 Cloud Storage with platform-specific add-ons:

* **WooCommerce** — Digital downloads via R2, product images, signed URLs per order
* **LearnDash** — Course videos and materials with signed URL protection
* **Tutor LMS** — Protected video streaming and course materials
* **Easy Digital Downloads** — Secure digital delivery via R2
* **MemberPress** — Protected member content via R2
* **BuddyBoss** — Community uploads stored on R2

= Developer Friendly =

```php
// Register your own add-on
add_action( 'r2cs_register_addon', function( $manager ) {
    $manager->register( 'my-addon', '1.0.0', 'My_Addon_Class' );
});

// Generate signed URLs
$url = r2cs()->signed_url()->generate( 'path/to/file.pdf', 3600 );

// Upload files programmatically
$result = r2cs()->client()->upload_file( '/local/path.pdf', 'remote/path.pdf' );
```

== Installation ==

1. Upload the plugin to `/wp-content/plugins/r2-cloud-storage/`
2. Activate through the Plugins menu
3. Go to **R2 Storage → Settings**
4. Enter your Cloudflare R2 credentials (Account ID, Access Key, Secret Key, Bucket)
5. Click "Test Connection" to verify
6. Enable automatic offload

= Getting R2 Credentials =

1. Log in to [Cloudflare Dashboard](https://dash.cloudflare.com/)
2. Navigate to **R2 Object Storage**
3. Copy your **Account ID** from the sidebar
4. Go to **Manage R2 API Tokens** → Create API Token
5. Copy the **Access Key ID** and **Secret Access Key**
6. Create a bucket if you haven't already

== Frequently Asked Questions ==

= Does this work with existing media? =

Yes! Use the **Sync** tool to migrate all existing media to R2.

= Will my images break if I deactivate the plugin? =

If you chose "Remove local copies", the local files are deleted. Keep backups. If local copies exist, WordPress will serve them normally when the plugin is deactivated.

= Can I use a custom domain? =

Yes. Set up a Custom Domain in Cloudflare R2 and enter it in the settings.

= Is this compatible with multisite? =

Yes. Network-activate the plugin and each site can have its own settings.

== External Services ==

This plugin connects to external third-party services as described below.

= Cloudflare R2 Object Storage =

This plugin connects to the Cloudflare R2 API to upload, retrieve, and delete media files stored in your R2 bucket. The connection is made to `https://<account_id>.r2.cloudflarestorage.com` using your configured credentials (Account ID, Access Key, and Secret Key).

Data is sent whenever media files are uploaded, deleted, synced, or served via the plugin. The data transmitted includes the file contents and metadata (object key/path).

* [Cloudflare Terms of Service](https://www.cloudflare.com/terms/)
* [Cloudflare Privacy Policy](https://www.cloudflare.com/privacypolicy/)

= R2 Cloud Storage License API =

This plugin connects to the R2 Cloud Storage license server at `https://r2cloudstorage.com/api/v1/license` to activate, deactivate, and verify add-on license keys. This connection is only made when a user manually activates or deactivates an add-on license, or when a periodic license verification is performed.

The data transmitted includes: the license key, the add-on slug, and the site domain.

* [R2 Cloud Storage Terms of Service](https://r2cloudstorage.com/terms)
* [R2 Cloud Storage Privacy Policy](https://r2cloudstorage.com/privacy)

== Changelog ==

= 1.0.0 =
* Initial release
* Media Library offload
* Pre-signed URLs
* Bulk sync tool
* Add-on manager
* pt_BR translation

== Upgrade Notice ==

= 1.0.0 =
Initial release.
