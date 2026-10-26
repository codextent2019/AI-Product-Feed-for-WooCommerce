=== Agentic Commerce & AI Product Feed for WooCommerce – ChatGPT Shopping, ACP, UCP & LLMs.txt ===
Contributors: codextent2019
Tags: agentic-commerce, product feed, acp, ucp, llms.txt
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
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

= Pro Features (Agentic Commerce Pro) =

* **BYOK (Bring Your Own Key) AI Auto-Fixer:** Connect your own OpenAI, Google Gemini, or Anthropic Claude API key to bulk-fix low AI Readiness Scores across thousands of products: auto-extracts missing `material`, `color`, `gender`, `age_group`, and `condition`; rewrites thin descriptions into factual, AI-quotable summaries; and generates conversational FAQPage JSON-LD schema.
* **15-Minute Real-Time Delta Feed Sync & .GZ Compression:** Uses WooCommerce Action Scheduler to update only modified SKUs, prices, and stock levels in the background and exports Gzip-compressed `.jsonl.gz` and `.csv.gz` feeds for high-volume catalogs.
* **ACP & UCP Agentic Checkout Endpoints (Model A & Model B):** Full ACP REST lifecycle with Stripe Shared Payment Token support and RFC 9421 / HMAC-SHA256 webhook signing (Model A), or a zero-Stripe universal cart deep-link checkout that works with PayPal, WooPayments, Square, or COD (Model B).
* **ChatGPT Ads Measurement Pixel & Conversions API:** Built-in browser + server-side conversion tracking with SHA-256 customer hashing, event ID deduplication, and WP Consent API compliance.
* **AI Channel Attribution & Revenue Dashboard:** Track orders, revenue, and crawl frequency originating from ChatGPT, Perplexity, Gemini, Copilot, and Claude.

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

= Where can I see which AI crawlers are blocked? =

Open **WooCommerce > Agentic AI Feed > AI discovery endpoints**. The built-in AI crawler monitor scans your effective robots.txt output for blocks against GPTBot, OAI-SearchBot, ChatGPT-User, PerplexityBot, ClaudeBot, Google-Extended, CCBot and Amazonbot, and live-probes /llms.txt and your feed URL.

== Changelog ==

= 1.0.0 =
* Initial release: OpenAI ACP product feeds (JSONL/CSV), /llms.txt + /llms-full.txt + /llms-commerce.txt generators, dual-protocol discovery manifests, AI Readiness Score engine, 3-step setup checklist, brand/GTIN field mapper, per-product AI feed metabox (brand/availability overrides, exclude toggle, conversational Q&A), AI bot allowlist & crawler monitor, and daily automatic rebuilds.
