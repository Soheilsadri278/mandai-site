TAKINTA EDITABLE SITE, ACCOUNTS & WOOCOMMERCE ORDERING

Install this plugin ZIP from WordPress Admin > Plugins > Add New > Upload Plugin. Keep the Takinta Static Site theme active and activate the plugin.

Open Tools > Takinta Content Import to import all bundled HTML pages. The plugin serves editable database copies on the existing .html routes, falling back to the theme's original static files until import is complete. Account links in the site header lead to the new account portal.

Use this plugin version 1.4.9 or later with Takinta Static Site theme 1.0.7 or later. Persian WooCommerce cart and checkout links retain the selected language; checkout labels and the order summary are translated and right-to-left on Persian routes. The configured cart page renders normally with a language query, avoiding redirect loops.

Account pages: /account/ and /register/; Persian: /fa/account/ and /fa/register/. Members can sign in, register as Subscriber, edit profile names, reset passwords, and sign out. With WooCommerce active, the site cart and checkout use WooCommerce; the legacy email purchase-request cart remains available only when WooCommerce is inactive.

Use WooCommerce > Takinta Draft Products / محصولات پیش‌نویس تکینتا to import the catalog in batches. Products remain drafts, with no price, SKU, or stock imported. The separate image-sync action is available on the same admin screen and can backfill existing products. Start it once; three-image batches continue automatically. It sideloads source images into the Media Library and sets them as featured images; products not created yet are skipped, so run the scan again after product creation. The admin screen reports unmatched products and sample failure reasons. Existing featured images are not overwritten. Enter each approved price manually and publish the product before it can be ordered. Existing products with matching slugs are skipped during product creation; image sync only adds a featured image when one is missing.

The plugin adds a manual card-to-card WooCommerce gateway. Configure the recipient card details under WooCommerce > Settings > Payments > Takinta Card-to-card transfer. Orders remain on hold until an administrator verifies the transfer; payment is not automatically confirmed. Store card details in WordPress settings, never in this plugin or GitHub.

A detailed Persian guide is included as EDITABLE-FA.md. Back up the database before importing, configure the site's privacy policy and shipping settings, and test checkout and WordPress email/SMTP before public launch.
