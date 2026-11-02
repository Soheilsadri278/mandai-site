TAKINTA EDITABLE SITE, ACCOUNTS, SHOPPING CART & WOOCOMMERCE DRAFT IMPORT

Install this plugin ZIP from WordPress Admin > Plugins > Add New > Upload Plugin. Keep the Takinta Static Site theme active and activate the plugin.

Open Tools > Takinta Content Import to import all bundled HTML pages. The plugin serves editable database copies on the existing .html routes, falling back to the theme's original static files until import is complete. Account links in the site header lead to the new account portal.

Account pages: /account/ and /register/; Persian: /fa/account/ and /fa/register/. Members can sign in, register as Subscriber, edit profile names, reset passwords, and sign out. The shopping cart is at /cart/ and /fa/cart/: it collects products and quantities and emails a purchase request for follow-up. It does not provide checkout, calculate prices, confirm orders, or take payment.

When WooCommerce is active, use WooCommerce > Takinta Draft Products / محصولات پیش‌نویس تکینتا to import the bundled catalog in batches. Imported products remain drafts and are never published or made purchasable by this tool. It stores both product titles and source-page references, but deliberately imports no price, SKU, stock, or media files. Existing products with matching slugs are skipped; imported items are not overwritten.

A detailed Persian guide is included as EDITABLE-FA.md. Back up the database before importing, configure the site's privacy policy, and test WordPress email/SMTP delivery before public launch.
