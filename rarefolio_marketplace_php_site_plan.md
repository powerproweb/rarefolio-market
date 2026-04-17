# RareFolio.io NFT Marketplace Site Plan
## PHP-First Blueprint for Primary Sales + Secondary Market Resales

**Prepared for:** RareFolio.io  
**Focus:** Museum-grade primary sales, trusted collector resale market, strong UX, and scalable PHP operations  
**Date:** April 16, 2026

---

## 1) Executive Direction

RareFolio should not feel like a noisy crypto bazaar. It should feel like a curated digital auction house crossed with a premium collector gallery.

The right product position is:

- **Primary market first:** launch curated drops, founder sales, gated releases, and showcase-level artist pages.
- **Secondary market built in:** once a RareFolio NFT is sold, the owner can list it for resale directly inside RareFolio.
- **Collector trust as a feature:** provenance, authenticity, trait verification, royalty clarity, artist identity, and transaction history should be visible and elegant.
- **Onboarding for normal humans:** wallet connect for crypto-native users, but clean onboarding for first-time collectors.
- **Museum-quality presentation:** oversized art views, clean typography, story-led layouts, strong curation, zero clutter.

This is not "just a marketplace." It is a **curated collector platform with a built-in resale economy**.

---

## 2) Recommended Product Model for RareFolio

### Core business model
RareFolio should support two tightly connected markets:

1. **Primary Sales Market**
   - Artist or RareFolio launches a collection/drop
   - Fixed-price, timed sale, auction, allowlist/gated access, or founder pre-sale
   - RareFolio takes a primary platform fee
   - Artist receives primary sale revenue

2. **Secondary Sales Market**
   - After purchase, collector can resell the NFT on RareFolio
   - Seller sets price or accepts offers
   - RareFolio takes secondary market fee
   - Royalty logic is displayed clearly and executed according to supported standard + marketplace rules
   - Full ownership history and sale history are shown on the asset page

### Recommended chain direction for RareFolio
Because RareFolio is positioned around **CNFT / Cardano-native collector identity**, the best-fit initial architecture is:

- **Phase 1 primary chain:** **Cardano**
- **Future multi-chain expansion:** Ethereum/Base/Polygon only after RareFolio nails brand and operations

Why Cardano first:

- RareFolio already lives conceptually in the CNFT lane
- Cardano native assets are first-class ledger assets rather than contract-only tokens
- CIP-25 is the long-standing metadata standard for many CNFTs
- CIP-68 is now the more advanced direction when dynamic or richer smart-contract-readable metadata is needed
- CIP-27 provides a royalty metadata convention, but enforcement should be treated as marketplace policy plus ecosystem support, not magic

### Marketplace positioning statement
**RareFolio is a curated Cardano-first collector platform where premium NFT art is launched, authenticated, and later traded through a trusted secondary market.**

---

## 3) Current 2026 Technical Reality You Should Design Around

This section matters because a 2022-era NFT plan is already old news.

### A. Wallet UX has to be easier than old-school crypto UX
Modern Web3 onboarding increasingly favors **smart-account / passkey-style experiences** and lower-friction transaction flows. On Ethereum-family chains this is heavily shaped by account abstraction; on Cardano, wallet connection standards and connector tooling still matter a lot. RareFolio should be designed so users can:

- connect a browser wallet fast
- sign only when necessary
- see exactly what action they are taking
- complete checkout with very few confusing steps

### B. Royalties must be handled honestly
Royalty support across NFT ecosystems is still a mix of standards, marketplace policy, and enforcement limitations. Your product should never pretend royalties are universally guaranteed everywhere.

For RareFolio, the correct stance is:

- show royalty terms clearly on each collection and NFT
- honor supported royalty metadata/logic inside RareFolio
- define RareFolio marketplace policy for secondary sales on your own platform
- educate users when an asset is transferred or traded outside RareFolio's marketplace rules

### C. The best marketplaces are data products, not just listing pages
Collectors now expect:

- activity feeds
- rarity and trait filtering
- clean provenance
- watchlists
- offers
- portfolio views
- seller reputation signals
- notifications
- fast search
- mobile-first flows

### D. Compliance and fraud controls are no longer optional
Even a curated art marketplace needs:

- wallet screening / sanctions checks for risky flows
- abuse reporting
n- suspicious behavior monitoring
- KYC/KYB options for artist payouts if fiat is involved
- tax and payout infrastructure for off-chain operations

---

## 4) Platform Goals

### Primary goals
- Sell curated RareFolio drops beautifully
- Allow post-sale secondary market listings natively
- Build collector trust and artist confidence
- Keep menus intuitive and elegant
- Make admin operations practical
- Support scale without rebuilding everything later

### Secondary goals
- Add social proof and collector prestige
- Support artist storytelling and editorial curation
- Increase retention through watchlists, offers, alerts, and collection activity
- Create a future bridge to memberships, DAO-style perks, and collector tiers

---

## 5) Information Architecture and Site Map

The menu should feel premium, not crowded. Think gallery first, utility second.

## Main Header Navigation

### Public Primary Nav
- **Home**
- **Drops**
- **Marketplace**
- **Artists**
- **Collections**
- **How It Works**
- **About RareFolio**
- **Support**

### Utility / Right Side Nav
- Search
- Watchlist
- Notifications
- Connect Wallet / Sign In
- User Avatar Menu

### Marketplace Subnavigation
- All NFTs
- New Listings
- Ending Soon
- Buy Now
- Auctions
- Offers
- Recently Sold
- Top Collections
- Top Artists

### Drops Subnavigation
- Live Drop
- Upcoming
- Past Drops
- Founder Sales
- Allowlist Access

### User Menu
- Dashboard
- My Collection
- My Listings
- My Offers
- Purchases
- Sales
- Payouts
- Profile
- Settings
- Security
- Logout

### Artist Menu
- Artist Studio
- My Collections
- Mint Queue
- Drafts
- Sales Analytics
- Royalties
- Payout Settings
- Verification

### Admin Menu
- Overview
- Users
- Artists
- Collections
- Listings
- Offers
- Auctions
- Orders
- Payouts
- Reports
- CMS
- Moderation
- Fraud & Risk
- Settings

---

## 6) Core User Types

### 1. Visitor
Can browse art, artists, collections, and sales without connecting a wallet.

### 2. Collector / Buyer
Can register, connect wallet, buy, offer, bid, watchlist, manage portfolio, and relist owned NFTs.

### 3. Seller
A collector who owns an NFT and lists it on the secondary market.

### 4. Artist
Creates collections, submits drops, manages profile, receives royalties/primary payouts, views analytics.

### 5. Curator / Moderator
Approves art, verifies metadata, manages featured content, handles abuse and quality control.

### 6. Admin / Ops
Runs commerce, payouts, fee rules, reporting, and dispute handling.

---

## 7) Signature Product Flows

## A. Primary Sale Flow
1. User lands on a collection or drop page
2. Sees hero artwork, story, edition info, supply, countdown, price, creator, royalty terms, chain details
3. Connects wallet or signs in
4. Completes purchase flow
5. Blockchain settlement confirmed
6. Ownership appears in user dashboard
7. NFT page updates ownership and activity history
8. Buyer can later list on secondary market if rules allow

## B. Secondary Listing Flow
1. User goes to **My Collection**
2. Selects an owned NFT
3. Clicks **List for Sale**
4. Chooses sale format:
   - fixed price
   - reserve auction
   - timed auction
   - accept offers only
5. Sets price, duration, minimum offer, optional reserve, optional private sale recipient
6. Reviews RareFolio fee and royalty display
7. Signs listing approval / listing order
8. NFT appears in marketplace with listing status live

## C. Secondary Purchase Flow
1. Buyer finds NFT via search, collection page, artist page, or watchlist alert
2. Opens asset detail page
3. Reviews price, provenance, seller, edition, traits, sale history, royalty and fee breakdown
4. Clicks buy now or place offer / bid
5. Signs transaction
6. Settlement recorded
7. Ownership transfers
8. Seller balance updates and payout ledger updates
9. Activity feed reflects sale in real time

## D. Offer Flow
1. Buyer makes offer on an asset
2. Seller receives notification
3. Seller accepts, counters, or declines
4. If accepted, order finalizes and ownership changes
5. Both parties receive receipts and activity updates

## E. Auction Flow
1. Seller or artist creates auction
2. Users bid
3. Auction countdown and anti-sniping extension logic apply
4. Highest valid bid at close wins
5. Settlement finalizes and ownership transfers

---

## 8) Feature Set: Must-Have for Launch

## Public Experience
- Premium home page with editorial-style featured collections
- Collection landing pages
- Artist profile pages
- NFT detail pages
- Marketplace browse/search/filter pages
- Activity feed
- FAQs and education pages
- Terms, privacy, risk, royalties explanation

## Buyer Features
- Wallet connect
- Email/social account layer for standard auth
- Profile and username
- Watchlist
- Notifications
- Purchase history
- Portfolio / collection view
- Make offers
- Bid in auctions
- Relist owned NFTs

## Seller Features
- List owned NFTs
- Accept / reject / counter offers
- Sales ledger
- Fee preview before listing
- Payout history
- Listing performance analytics

## Artist Features
- Verified artist profile
- Collection builder
- Upload art/media/metadata
- Draft and preview pages
- Primary sale configuration
- Royalty setup
- Sales analytics
- Collector insights
- Featured story sections

## Admin Features
- Manual collection approval
- Artist verification workflow
- CMS blocks for landing pages and curation
- Listing moderation
- Order monitoring
- Fraud/risk flags
- Fee settings
- Royalty policy controls
- Payout management
- support ticket tools
- reporting and export tools

---

## 9) Feature Set: Strongly Recommended “Bells and Whistles”

These are the things that make the marketplace feel premium and competitive.

### Collector Experience Enhancers
- Recently viewed items
- Compare mode for multiple NFTs
- Collection floor price and sales history widgets
- Trait rarity view
- Collection completion progress
- Wishlist folders
- Portfolio value estimates
- "Collectors also viewed" recommendations
- Push/email alerts for watched NFTs, offers, outbids, and sales

### Prestige / Trust Enhancers
- Verified artist and verified collection badges
- Provenance timeline on each NFT
- Signed creator statement / certificate page
- High-resolution zoom + frame mode
- Exhibition-style detail page layout
- Edition numbering and ownership graph
- Transaction transparency panel

### Commerce Enhancers
- Bulk list management
- Bundle listings
- Private sale links
- Time-limited offers
- Escrow-style holding period for certain cases
- Reserve auctions
- anti-sniping extensions
- floor sweep tools for advanced collectors

### Community / Retention Enhancers
- Follow artist
- Follow collection
- Curated editorial articles
- Exhibition rooms / themed galleries
- collector achievements / badges
- loyalty tiers
- allowlist and token-gated drops

---

## 10) Design System Direction

## Brand feel
RareFolio should look like:

- luxury gallery
- museum exhibition catalog
- premium auction platform
- modern fintech control panel underneath

## Visual principles
- large art-first hero areas
- rich whitespace
- restrained color palette
- one strong accent color for actions
- elegant serif + clean sans pairing
- quiet motion, not flashy motion
- dark mode by default, light mode optional
- deep focus on hierarchy and legibility

## Layout principles
- art gets the most space
- menus stay shallow and predictable
- filters open in clean side panels
- mobile gets bottom action bars for buy/watch/share
- all commerce actions stay sticky but unobtrusive

## Recommended page patterns

### Home
- cinematic hero
- featured drop
- curated collections row
- trending secondary listings
- verified artists
- editorial section
- trust section
- CTA footer

### Marketplace Grid
- left filter rail on desktop
- top sorting + quick chips
- large cards with price, edition, artist, collection, chain, and status
- save/watch action on card
- sale type badges

### NFT Detail Page
- oversized media panel
- right-side action panel
- tabs for details / traits / provenance / offers / activity / artist story
- sticky buy box
- visible fee + royalty breakdown
- visible ownership history

### Artist Page
- banner + portrait
- biography / mission / links
- verified badge
- live collections
- sold works
- secondary market activity for that artist

---

## 11) Technology Recommendation

## Core stack
### Backend
- **PHP 8.3+**
- **Laravel 12** as the main application framework
- Laravel Horizon for queues
- Laravel Sanctum or Passport depending on auth model
- Laravel Cashier only if you later add fiat subscriptions/memberships

### Frontend
Two strong options:

#### Option A: Laravel + Inertia + React + TypeScript
Best if you want highly interactive marketplace UX while keeping one Laravel codebase.

#### Option B: Laravel Blade + Livewire + Alpine
Best if you want a more PHP-centric stack with lower JS complexity.

**Recommendation:** use **Laravel + Inertia + React + TypeScript** for RareFolio. It gives you premium interactivity without turning the entire app into a separate SPA headache.

### Database / infra
- **PostgreSQL** for core relational data
- **Redis** for cache, sessions, queues, throttling, notifications
- **S3-compatible object storage** for media derivatives and uploads
- **CDN** for image/media delivery
- **Meilisearch or OpenSearch** for fast marketplace search/filtering

### Real-time
- Laravel Reverb / WebSockets or Pusher-compatible broadcasting
- Used for bids, offer notifications, live sale updates, activity feed, admin alerts

### Blockchain integration
Because RareFolio is PHP-first but blockchain operations on Cardano are more naturally handled in dedicated tooling, use:

- **Laravel as the product/control plane**
- **A separate Cardano service** for chain-specific actions

That sidecar service can be:
- Node.js/TypeScript using Cardano ecosystem tooling
- or a dedicated provider integration

This service should handle:
- wallet signature request preparation
- transaction build payloads
- metadata validation
- mint pipeline orchestration
- listing/order signing helpers
- settlement watchers / indexer sync

**Reality check with a wink:** forcing every chain-heavy workflow directly into pure PHP is how a beautiful marketplace turns into a heroic debugging documentary.

---

## 12) Cardano / CNFT Architecture Recommendation

## Standards to support
### Launch baseline
- **CIP-25** for standard NFT metadata compatibility
- **CIP-27** royalty metadata support for CNFT ecosystem compatibility
- **CIP-30** wallet bridge support for web wallet integration

### Future-ready support
- **CIP-68** for more advanced metadata and richer programmable behavior

## Practical recommendation
For RareFolio v1:
- support **CIP-25** collections first for broad compatibility
- architect the system so **CIP-68** collections can be added later without schema rewrite
- support **CIP-27** royalty metadata where applicable, but present royalties as RareFolio marketplace policy + displayed metadata, not universal chain-enforced truth

## Ownership and resale model
RareFolio should maintain an indexed ownership layer in its own database.

That means you track:
- collection
- policy ID / asset ID
- token name
- current owner wallet
- custody status
- listing status
- verified transfer history
- marketplace sale history

This local index is essential for a clean secondary market because the site has to know:
- who really owns the NFT
- whether it is currently listed
- whether a listing is stale
- whether an offer is still valid
- what royalties/fees apply inside RareFolio

---

## 13) Wallet and Authentication Strategy

## Web2 + Web3 hybrid auth
Do not force users into a pure wallet-only experience.

Use a hybrid model:

- Email/password or magic link account
- Optional social sign-in later
- Wallet connect for ownership, purchase, listing, and withdrawal actions
- Multiple wallets per account
- One primary payout wallet per user

## Cardano wallet support
Support CIP-30 compatible browser wallet flows.

### User actions that require wallet signature
- connect wallet
- verify ownership
- buy NFT
- list NFT for sale
- cancel listing
- accept offer
- bid in auction
- transfer or withdraw where relevant

## Session model
- Standard app session for browsing and account activity
- privileged wallet-signed actions for on-chain sensitive actions
- step-up verification for payout or security changes

---

## 14) Detailed Module Breakdown

## A. User Account Module
**Features**
- registration/login
- profile setup
- avatar, banner, bio
- connected wallets
- notification preferences
- 2FA / passkey-ready support later
- account status and role flags

## B. Artist Studio Module
**Features**
- artist application and verification
- collection creation wizard
- metadata entry
- media uploads
- artist statement
- drop schedule
- royalty setup
- sales dashboard

## C. Collection Management Module
**Features**
- collection draft/publish lifecycle
- edition settings
- rarity / traits schema
- reveal options
- drop scheduling
- allowlist management
- collection verification badge

## D. NFT Asset Module
**Features**
- metadata display
- media rendering
- provenance timeline
- owner history
- sale history
- trait filters
- authenticity indicators

## E. Primary Sale Engine
**Features**
- fixed-price drops
- timed sale windows
- reserve release logic
- purchase limit per wallet/account
- allowlist / token gate support
- countdown timers
- sold-out states

## F. Secondary Marketplace Engine
**Features**
- fixed price listing
- timed auctions
- reserve auctions
- offer system
- private sales
- bundle support later
- listing validation
- listing expiration and renewal
- marketplace fee calculation
- royalty display/application

## G. Order / Settlement Module
**Features**
- order creation
- pending / signed / submitted / settled / failed states
- chain tx reference
- settlement retry / reconciliation
- stale order invalidation
- fee and royalty ledger entries

## H. Search / Discovery Module
**Features**
- keyword search
- artist search
- collection search
- traits filtering
- price range
- sale type
- verified only
- recently listed / sold / ending soon
- curated sort modes

## I. Notification Module
**Channels**
- in-app
- email
- optional SMS later

**Events**
- offer received
- offer accepted/rejected/countered
- outbid
- sale completed
- listing expiring
- payout sent
- drop launching soon

## J. CMS / Editorial Module
**Features**
- home page curation
- featured artists
- banners
- editorial stories
- collection spotlights
- content blocks by campaign

## K. Support / Trust Module
**Features**
- report listing
- report fraud
- help center
- ticketing integration
- dispute notes for admins
- refund policy documentation where applicable

---

## 15) Database Planning (High-Level)

Below is the relational backbone. You do not need every field listed now, but the table families should exist in the plan.

## Core tables
- users
- user_profiles
- roles
- permissions
- wallets
- wallet_verifications
- artists
- artist_verifications
- collections
- collection_contracts_or_policy_records
- collection_traits
- nfts
- nft_media
- nft_attributes
- nft_ownership_snapshots
- nft_activity
- listings
- listing_terms
- offers
- auctions
- bids
- orders
- settlements
- royalty_rules
- platform_fee_rules
- payouts
- payout_accounts
- notifications
- watchlists
- follows
- reports
- moderation_actions
- cms_pages
- cms_blocks
- audit_logs

## Critical listing fields
- listing_id
- nft_id
- seller_user_id
- seller_wallet_id
- sale_type
- asking_price
- reserve_price
- currency
- start_at
- end_at
- status
- signed_payload_reference
- chain_reference
- canceled_at
- settled_at

## Critical NFT fields
- nft_id
- collection_id
- policy_id
- asset_name
- asset_fingerprint
- token_standard
- metadata_version
- title
- slug
- current_owner_wallet
- current_owner_user_id_nullable
- mint_tx_hash
- verified_status
- primary_sale_status
- secondary_sale_eligible

---

## 16) Search and Discovery Logic

To make the secondary market actually useful, discovery has to be excellent.

## Search dimensions
- keyword
- artist
- collection
- token name
- policy ID / asset fingerprint
- trait values
- price range
- currency
- sale format
- availability
- edition number
- verified status
- newly listed
- ending soon
- recently sold

## Sort modes
- featured
- newest
- price low to high
- price high to low
- recently sold
- most viewed
- most watched
- ending soon
- highest offers

## Curated surfaces
- Trending this week
- New from verified artists
- Rare finds
- Most watched
- Fresh secondary listings
- Under X ADA
- Museum picks / curator picks

---

## 17) Fee, Royalty, and Payout Logic

## Platform fee model
RareFolio should support separate fee logic for:

- primary sales
- secondary sales
- special campaigns / negotiated artist terms

## Royalty handling
Your system should support:

- collection-level royalty defaults
- token-level royalty overrides if needed
- clear buyer/seller fee preview before confirmation
- admin-configurable policy by collection

## Payout handling
### For crypto-native payouts
- pay artist/seller wallet after settlement confirmation and reconciliation
- hold failed/pending cases in review queue

### If fiat ramps are added later
- support payout provider/KYC workflow
- maintain platform ledger, seller ledger, and payout ledger separately

## Accounting principle
Never rely only on chain activity for reporting. Maintain internal financial ledgers for:
- gross sale amount
- platform fee
- royalty amount
- seller net amount
- payout status
- refund/adjustment entries

---

## 18) Security, Risk, and Moderation

## Security must-haves
- CSRF/XSS/SQLi standard protections via Laravel best practices
- signed URL handling for private media workflows
- rate limiting on auth, offer, and bidding endpoints
- 2FA for admins and artists
- role-based access control
- audit logging for admin actions
- secure API key management
- queue isolation for sensitive jobs
- encrypted secrets and wallet-related metadata storage where applicable

## Marketplace-specific risk controls
- wallet screening hooks for high-risk addresses
- duplicate / fake collection detection
- stolen asset report workflow
- suspicious bidding behavior alerts
- anti-spam offer throttling
- failed settlement reconciliation
- admin review queue for flagged transfers/listings

## Content moderation
- artist verification
- collection verification
- metadata review
- image/content policy review
- takedown workflow

---

## 19) API Strategy

RareFolio should be API-first internally, even if the first UI is server-driven through Laravel.

## Internal API domains
- auth
- users
- wallets
- artists
- collections
- nfts
- marketplace listings
- offers
- auctions
- orders
- payouts
- notifications
- admin

## External integrations to keep room for
- wallet connectors
- blockchain indexer/provider
- sanctions/risk screening provider
- image optimization service
- email provider
- analytics provider
- fiat payment provider later

---

## 20) Recommended Pages in V1

## Public Pages
- /
- /drops
- /drops/{slug}
- /marketplace
- /marketplace/{collection}
- /nft/{slug-or-asset}
- /artists
- /artist/{slug}
- /collections
- /how-it-works
- /about
- /support
- /terms
- /privacy
- /royalties

## Authenticated Pages
- /dashboard
- /dashboard/collection
- /dashboard/listings
- /dashboard/offers
- /dashboard/purchases
- /dashboard/sales
- /dashboard/watchlist
- /dashboard/notifications
- /dashboard/settings
- /dashboard/security

## Artist Pages
- /studio
- /studio/collections
- /studio/collections/create
- /studio/nfts
- /studio/drops
- /studio/analytics
- /studio/payouts
- /studio/profile

## Admin Pages
- /admin
- /admin/users
- /admin/artists
- /admin/collections
- /admin/nfts
- /admin/listings
- /admin/orders
- /admin/payouts
- /admin/reports
- /admin/moderation
- /admin/settings

---

## 21) UX Details That Will Matter More Than People Think

- Wallet connect must never hijack the entire page unexpectedly
- Fee and royalty preview must appear before final signature
- Users must always know whether they are buying from primary or secondary market
- Provenance must be visible without digging
- Buttons should be state-aware: listed, owned, sold, make offer, cancel listing, place bid
- Empty states should still feel premium
- Search results should load fast and remain filter-sticky
- Mobile asset page needs sticky CTA bar
- Every transaction state needs human language: pending, confirming, completed, failed, action needed

---

## 22) Recommended Build Phases

## Phase 1: Foundation + Primary Sales
**Goal:** launch RareFolio as a world-class curated sales platform.

Includes:
- auth + wallet connect
- artist profiles
- collections
- NFT detail pages
- primary drop engine
- admin curation tools
- watchlist
- activity tracking backbone
- foundational ledger tables

## Phase 2: Secondary Marketplace Core
**Goal:** allow owners to resell purchased NFTs on RareFolio.

Includes:
- ownership indexing
- fixed-price listings
- listing management
- buy now flow
- activity feed
- marketplace search + filters
- seller dashboard
- royalty + fee logic

## Phase 3: Offers + Auctions + Notifications
**Goal:** add full collector trading behavior.

Includes:
- offers
- counters
- auctions
- anti-sniping
- real-time notifications
- more advanced analytics

## Phase 4: Prestige + Growth Layer
**Goal:** make RareFolio feel elite and sticky.

Includes:
- editorial curation engine
- rarity tools
- collection stats
- recommendations
- badges / achievements
- loyalty or collector tiering
- deeper reporting

## Phase 5: Advanced Expansion
**Goal:** future-proof the business.

Includes:
- fiat rails
- passkey/smart-account-inspired onboarding where chain stack allows
- multi-chain support
- CIP-68-rich experiences
- memberships / token gating / private vaults

---

## 23) Recommended Team Structure

## Product / Design
- Product owner
- UX/UI designer
- brand/visual designer

## Engineering
- Laravel lead engineer
- frontend React/TypeScript engineer
- Cardano integration engineer
- DevOps engineer
- QA engineer

## Marketplace Operations
- curator/content manager
- artist onboarding manager
- support/risk operations

---

## 24) What Not to Do

- Do not launch with a cluttered mega-menu
- Do not rely on wallet-only identity for every user action
- Do not hide fee math
- Do not treat royalties as universally guaranteed in all contexts
- Do not build secondary market logic as an afterthought
- Do not let stale listings remain live
- Do not use a generic template look for an art-first platform
- Do not force every blockchain function into pure PHP if ecosystem tooling is better elsewhere

---

## 25) Final Recommendation

### Best overall direction
Build **RareFolio.io as a Cardano-first, Laravel-powered curated NFT marketplace** with two commercial engines:

1. **Primary Drops Engine** for launches and founder sales  
2. **Secondary Collector Marketplace** for verified resales of owned NFTs

### Best stack choice
- Laravel 12
- Inertia + React + TypeScript
- PostgreSQL
- Redis
- Meilisearch/OpenSearch
- S3-compatible media storage
- separate Cardano integration/indexing service

### Best product philosophy
The marketplace should feel like a **museum-grade collector exchange**, not a noisy trading floor.

### Best rollout strategy
- Nail primary sales and ownership indexing first
- Then release fixed-price secondary listings
- Then add offers and auctions
- Then layer prestige, editorial, and growth features

That order keeps the build realistic while still aiming high.

---

## 26) RareFolio-Specific Product Summary

Here is the simplest expression of what you are building:

**RareFolio sells premium NFT art through curated primary drops. Once a buyer owns that NFT, RareFolio can let that owner relist it inside RareFolio's secondary marketplace, with visible provenance, fee logic, royalty handling, and collector-grade presentation.**

That is the right center of gravity.

Not a crypto flea market.  
A sovereign collector economy with polish.

---

## 27) Current Standards and Source Notes

This plan reflects current documentation and ecosystem behavior checked on April 16, 2026.

### Cardano references
- Cardano native tokens overview: https://docs.cardano.org/developer-resources/native-tokens
- Cardano developer portal, native tokens overview: https://developers.cardano.org/docs/build/native-tokens/overview/
- CIP-25 NFT metadata standard: https://cips.cardano.org/cip/CIP-25
- CIP-27 CNFT royalty metadata standard: https://cips.cardano.org/cips/cip27
- CIP-30 wallet bridge standard: https://cips.cardano.org/cip/CIP-30
- CIP-68 datum metadata standard: https://cips.cardano.org/cip/CIP-68
- Cardano dev portal note that CIP-68 is now the preferred standard for more complex NFT functionality: https://developers.cardano.org/docs/build/native-tokens/minting-nfts/

### PHP / app stack references
- Laravel 12 release notes: https://laravel.com/docs/12.x/releases

### Broader NFT marketplace references
- OpenSea creator earnings model (optional or enforced depending on collection/platform handling): https://support.opensea.io/en/articles/8867026-how-do-i-set-creator-earnings-on-opensea
- EIP-2981 royalty interface reference: https://eips.ethereum.org/EIPS/eip-2981
- OpenSea developer API overview: https://docs.opensea.io/reference/api-overview
- Account abstraction overview: https://ethereum.org/roadmap/account-abstraction
- Coinbase smart wallet overview: https://help.coinbase.com/en-in/wallet/getting-started/smart-wallet
- Stripe Connect platform/marketplace docs: https://docs.stripe.com/connect
- MoonPay NFT checkout overview: https://dev.moonpay.com/v1.0/docs/nft-checkout-product-overview
- TRM sanctions API docs: https://docs.sanctions.trmlabs.com/
- Chainalysis sanctions oracle docs: https://go.chainalysis.com/chainalysis-oracle-docs.html

