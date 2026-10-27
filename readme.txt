=== Agentic Commerce & AI Product Feed for WooCommerce – ChatGPT, ACP, UCP & LLMs.txt ===
Contributors: codextent2019
Tags: agentic-commerce, product feed, acp, ucp, llms.txt
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
WC requires at least: 7.6
WC tested up to: 11.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate OpenAI ACP & ChatGPT Shopping product feeds (JSONL/CSV), Google UCP discovery, /llms-full.txt, and AI Readiness Scores for WooCommerce.

== Description ==

**Make your WooCommerce catalog discoverable, recommended, and buyable across ChatGPT Shopping, OpenAI Ads, Perplexity Shopping, Google AI Mode (Gemini), and Claude.**

When 700M+ weekly shoppers ask AI assistants *"Find me waterproof trail running shoes in size 10 under $100 that ship in 2 days"*, AI agents do not browse bloated HTML pages. They ingest structured **OpenAI Commerce Product Feeds (JSONL/CSV)**, query **Agentic Commerce Protocol (ACP)** and **Universal Commerce Protocol (UCP)** manifests, and read clean **/llms.txt** and **/llms-full.txt** catalog indexes.

**Agentic Commerce & AI Product Feed for WooCommerce** is the complete, self-hosted AI commerce suite. No external SaaS middleman, no 25-product limits, and zero performance impact on your storefront.

= Free Features (100% Self-Hosted, Unlimited Products) =

* **OpenAI ACP & ChatGPT Product Feed Generator (JSONL & CSV):** Automatically builds spec-compliant product feeds following OpenAI's official Commerce Feed Reference (`item_id`, `group_id`, `listing_has_variations`, `variant_dict`, `brand`, `seller_name`, `gtin`, `mpn`, `availability`, `price`, `shipping`, and `return_policy`).
* **Smart Variable Product Flattening:** Expands every WooCommerce product variation into its own spec-compliant row while linking parent `group_id` and `variant_dict` attributes (`color`, `size`, `material`).
* **WebP/AVIF to JPEG/PNG Auto-Resolver:** OpenAI requires `image_url` to be JPEG or PNG. If your site uses WebP/AVIF optimization plugins, our feed engine automatically resolves or generates compliant JPEG/PNG URLs so ChatGPT carousels never skip your products.
* **E-Commerce /llms.txt & /llms-full.txt Generator:** Publishes clean Markdown catalog indexes with live SKU pricing, variation matrices, stock availability, and shipping/return terms. Seamlessly appends to Yoast SEO or Rank Math /llms.txt if already active.
* **Dual-Protocol Discovery (/.well-known/acp.json & /.well-known/ucp):** Publishes compliant discovery profiles for both OpenAI's Agentic Commerce Protocol (ACP) and Google/Shopify's Universal Commerce Protocol (UCP).
* **AI Readiness Score (0–100) in Products List:** Adds a real-time diagnostic badge directly inside your Products > All Products screen, flagging missing Brands, invalid GTIN check digits, thin descriptions, or missing attributes that block AI recommendations.
* **1-Click Brand & GTIN Field Mapper:** Map existing GTIN, UPC, EAN, ISBN, MPN, and Brand values from WooCommerce Core, Yoast, Rank Math, Germanized, or any custom meta key.
* **AI Bot Allowlist & Crawler Monitor:** Ensures `OAI-SearchBot`, `ChatGPT-User`, `GPTBot`, `PerplexityBot`, `ClaudeBot`, and `Google-Extended` can access your feeds and product schema without being blocked.

== Installation ==

1. Install and activate **Agentic Commerce & AI Product Feed for WooCommerce**.
2. Complete the **3-Step Setup Wizard** (Map Brand/GTIN sources, set Seller Name & Return Policy URL, and generate your first feed).
3. Copy your Hosted JSONL/CSV Feed URL from **WooCommerce > Agentic AI Feed** to submit to OpenAI Merchant / Ads Manager, and review your **AI Readiness Score** under **Products > All Products**.

== Frequently Asked Questions ==

= Does the free version require an external account or API key? =

No. All feed generation (`.jsonl`, `.csv`, `/llms.txt`, `/llms-full.txt`), discovery manifests (`/.well-known/acp.json`, `/.well-known/ucp`), and AI Readiness Scores run 100% locally on your WordPress server with no product limits.

= Will this conflict with Yoast SEO, Rank Math, or WooCommerce Core ACP? =

No. Our Coexistence Engine detects if Yoast or Rank Math is managing `/llms.txt` or `Product` JSON-LD and extends their output via native filters rather than duplicating files or markup. It also checks WooCommerce 10.7+ native `agentic_checkout` feature flags automatically.

= Why does ChatGPT reject my product images? =

OpenAI's Commerce Feed spec requires `image_url` to be a direct JPEG or PNG file. If your store serves `.webp` or `.avif` images, ChatGPT Shopping carousels may drop them. Our plugin automatically resolves the underlying JPEG/PNG image for every feed row.

= How do I hide a single product from AI channels? =

Open the product, find the **AI Product Feed (ChatGPT / Agentic)** box, and tick "Exclude this product from all AI feeds and llms.txt output". The product disappears from the JSONL/CSV feeds, llms-full.txt and its list badge switches to *Excluded*.

= Where do the Conversational Q&A pairs I add in the product metabox show up? =

They appear in `/llms-full.txt` inside the product block (as "FAQ: question / Answer: ..." lines), so AI assistants can quote them verbatim. They also power the FAQ check of the AI Readiness Score (2+ pairs = full points). The OpenAI JSONL/CSV feed intentionally does not carry them: the OpenAI Commerce Feed spec has no FAQ field, and unknown fields can make a feed row invalid.

= Can I change how often the feeds rebuild? =

Yes. Open **WooCommerce > Agentic AI Feed > Settings** and pick Hourly, Twice a day, Once a day (default), Once a week, or External cron / manual only. There is also a toggle to rebuild automatically whenever product data changes (batched, about a minute after the save), and a secret rebuild URL you can call from a real cron job or uptime monitor.

= Where can I see which AI crawlers are blocked? =

Open **WooCommerce > Agentic AI Feed > AI discovery endpoints**. The built-in AI crawler monitor scans your effective robots.txt output for blocks against GPTBot, OAI-SearchBot, ChatGPT-User, PerplexityBot, ClaudeBot, Google-Extended, CCBot and Amazonbot, and live-probes /llms.txt and your feed URL.

== Changelog ==

= 1.0.0 =
* Initial release: OpenAI ACP & ChatGPT Shopping product feeds (JSONL/CSV), /llms.txt + /llms-full.txt + /llms-commerce.txt generators, dual-protocol discovery manifests (/.well-known/acp.json & /.well-known/ucp), UCP catalog search REST endpoint, and daily automatic rebuilds.
* Smart variable product flattening: one purchasable feed row per variation, unique option combinations only, consistent variant_dict keys completed from parent defaults, parent group_id linking, and per-variation add-to-cart deep links in llms-full.txt.
* Spec-compliant output: WebP/AVIF to JPEG/PNG auto-resolver with right-sized image selection, HTML-entity decoded titles/descriptions, short-description fallback, and a UTF-8 BOM on CSV so Excel renders multibyte titles correctly (JSONL stays BOM-free).
* Deterministic AI Readiness Score (0-100): 7-point audit with GTIN Modulo-10 validation, products-list badge column with score-band filter and bulk recalculation, and a per-product AI feed metabox (brand, availability, condition, gender and age group overrides, exclude toggle, conversational Q&A surfaced in llms-full.txt and FAQPage JSON-LD).
* Product JSON-LD enrichment: injects hasMerchantReturnPolicy and shippingDetails into Product schema and publishes FAQPage JSON-LD, extending Yoast SEO and Rank Math output via native filters when active.
* 1-click brand & GTIN field mapper, AI bot allowlist & crawler monitor, 3-step setup checklist, configurable rebuild schedule (hourly / twice daily / daily / weekly / external cron with secret URL, plus optional rebuild-on-product-change), and opt-in clean uninstall.
* Merchant logo resolution for discovery manifests: Customizer Site Logo preferred, Site Icon fallback, manual override URL in settings, all filterable via acpfw_merchant_logo.
