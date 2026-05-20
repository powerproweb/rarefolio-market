# Founders Companion Token Transaction Ledger (2026-05-19)
## Scope
This ledger records the companion-token incident correction sequence for Founders `qd-silver-0000705` through `qd-silver-0000712`, including mistaken sends, reclaim, corrected one-per-token sends, and final burn cleanup.

## Units and wallets
- Real companion unit (ARGENTUM_PRIME_Bar01):  
  `46cd5216baf9e1e81771731570e408fb4c392cc38db59f55ee8599a1415247454e54554d5f5052494d455f4261723031`
- Mistaken V2 unit (quarantined then burned):  
  `82ae9440500e297e49144a13832861de3e84e526eee0eb70f4d48af751444656325431373739313238313330`
- Founders treasury wallet:  
  `addr1qy7q642xykq3mg04s6mdyvfc7mz96hz4qv6p4ncnwyzhkrdu3908k8zq4lt7upj7nq5f6gk0q4x72kt0246xtmea077sa0kvus`
- FOUNDERS_V2 policy wallet:  
  `addr1q9zrpk238v6gkhltkk0dr55x39t0zp50e87wrdurmg0gmlt3m5un8ekjtsuc067hc9hra3s7kg6m3z2xwd79phz5v5sqpv5ylq`

## 1) Mistaken V2 companion sends (earlier run)
- `qd-silver-0000705` -> `aa073a9905c7643d91b078f478cdaa8930256a58beb3ea1dedf34648abdea19d`
- `qd-silver-0000709` -> `04656137503e134cba51d1bda7bab47e9cb3a483d35bf1b29ef853db617d4a78`
- `qd-silver-0000711` -> `c509b8decf100f093e8c00d612bdb759fdbd34519cf19958f206387009052208`
- `qd-silver-0000712` -> `3b2ede90efa2b687a28e39278ef74f8085307db9fe890e9810e2abea629d181d`

## 2) Reclaim of mistaken V2 unit
- Reclaimed `4` V2 tokens back under controlled custody:  
  `8d54e51fe0b369156f36e888f810c0d9b38c74f6a11219b62c20b1497184b339`

## 3) Correct real companion sends (quantity = 1 each)
- `qd-silver-0000712` -> `f61376d2e014fc5f9215fdfa182e1a1fd90b7150f88f94021e5e214939a15da1`
- `qd-silver-0000711` -> `96d493411c8a13e6625c3bfcc9d089691b8d3377918fe27b9e68aba0e8835bb9`
- `qd-silver-0000710` -> `c55343c07e0e4f77bb048cfdf059499955ad59b3b55b66001fddd85ad69ee7f8`
- `qd-silver-0000709` -> `f870e90f0092f7065a5ecb6c90c2844d28129b651f969302adee22296764ff64`
- `qd-silver-0000708` -> `6499381547c1117b93f54c7c1e6c3871cf83f0922f61616316a778567fc98cb0`
- `qd-silver-0000707` -> `9b800c81aef49f7558d8e5ab1a5866086257fa36016bc7cce0b10a674f4169b9`
- `qd-silver-0000706` -> `25942e9bce762c6024f0af76eb131e99ec2ba59e350dfeec7cc85c18e4834862`
- `qd-silver-0000705` -> `b83dda76227aa6b40af72ac998ef02cedf4a654337e533d0f11c607a33380851`

## 4) Quarantine transfer to V2 policy wallet and burn
- Moved quarantined V2 tokens to V2 policy wallet for burn:  
  `268adfba61e9efdd7d957448ed878a37c7a2d53c9233cf88a9ed92444fd10785`
- Burned `4` V2 tokens on-chain:  
  `4e7e4348a93cafd71eb801df0977816b5eb295b88da6f482f4ee04c1e5bed18c`

## 5) Final state target confirmation
- Founders treasury real companion unit quantity: **29,992**
- Founders treasury V2 quarantined unit quantity: **0**
- Blockfrost asset quantity for V2 quarantined unit: **0**
