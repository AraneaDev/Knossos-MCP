# Changelog

## [0.12.0](https://github.com/AraneaDev/Knossos-MCP/compare/v0.11.0...v0.12.0) (2026-08-24)


### Features

* bring the Python and Rust workers to parity with PHP and TypeScript ([dbfdf81](https://github.com/AraneaDev/Knossos-MCP/commit/dbfdf81d805b3e27f7be93d7e69a1da9f9819165))
* bring the Python and Rust workers to parity with PHP and TypeScript ([0544d21](https://github.com/AraneaDev/Knossos-MCP/commit/0544d212e967116c3a75f5dd295a4715b166b9b1))


### Bug Fixes

* **ci:** stop the quality gate depending on badge-host availability ([4b03dbf](https://github.com/AraneaDev/Knossos-MCP/commit/4b03dbf8e4606379dc13f1f9a4272ab98afcdd69))
* close the manifest, classification, and cache gaps found in review ([c76ed97](https://github.com/AraneaDev/Knossos-MCP/commit/c76ed97af60cc06ab46edcc8d845499148e4c9f5))
* **discovery:** anchor Python and Cargo entry points to their manifest ([0cb7aca](https://github.com/AraneaDev/Knossos-MCP/commit/0cb7acacc56580bbce41cc846e4f93d12af56169))
* **discovery:** follow Cargo's own binary auto-discovery rules ([423ce41](https://github.com/AraneaDev/Knossos-MCP/commit/423ce413c90369ee15e051bf4b1bf587bd51441d))
* **discovery:** record the real crate behind a renamed Cargo dependency ([a0284c3](https://github.com/AraneaDev/Knossos-MCP/commit/a0284c3bdd84fb3dabac09098641f9b09c959648))

## [0.11.0](https://github.com/AraneaDev/Knossos-MCP/compare/v0.10.6...v0.11.0) (2026-08-23)


### Features

* **discovery:** classify Rust sources and Cargo manifests ([a60d42c](https://github.com/AraneaDev/Knossos-MCP/commit/a60d42cdb0dc3771001a75710975af0b604946f6))
* **doctor:** report an absent optional worker as skipped, not silent ([4cc32ea](https://github.com/AraneaDev/Knossos-MCP/commit/4cc32ea065a5648e877f73a0b2837805616c19cc))
* **rust:** add the Rust scanner worker protocol skeleton ([3be8c0e](https://github.com/AraneaDev/Knossos-MCP/commit/3be8c0eeed198c0648ce8a2e2cd968eb6edb2988))
* **rust:** emit a module node per scanned file ([f48e8dd](https://github.com/AraneaDev/Knossos-MCP/commit/f48e8dd2948937a43b08b6be5a36b2c9a6a73e5b))
* **rust:** emit declaration nodes and containment edges ([0812767](https://github.com/AraneaDev/Knossos-MCP/commit/081276756f8f429cc4d47294f7b7e23a3db51caf))
* **rust:** emit implements and supertrait edges ([abbab90](https://github.com/AraneaDev/Knossos-MCP/commit/abbab906bad1467ff2c61510aa14bc0136674e1d))
* **rust:** resolve call expressions into calls edges ([de0bb34](https://github.com/AraneaDev/Knossos-MCP/commit/de0bb344c94e9406e1fe082f362498b0aac9bb16))
* **rust:** resolve use declarations into import edges ([9ac4159](https://github.com/AraneaDev/Knossos-MCP/commit/9ac41596840eeed35ed0e8ca204e03941a0f3546))
* **scan:** add a Rust scanner worker ([730e7d0](https://github.com/AraneaDev/Knossos-MCP/commit/730e7d0e32c8e0ab2cbb00831122b2fc4c0efb13))
* **scan:** register Rust as an optional packaged language ([2af0a60](https://github.com/AraneaDev/Knossos-MCP/commit/2af0a60e96002855e7af30cbf85fdaca3c75b742))


### Bug Fixes

* **boundary:** infer a boundary for a cargo manifest ([180e271](https://github.com/AraneaDev/Knossos-MCP/commit/180e2716bbafb4d78a253dee64c75ede97fbcd8d))
* **discovery:** scope the Cargo package-name regex to the [package] table ([aee7847](https://github.com/AraneaDev/Knossos-MCP/commit/aee78479b717781456a0a6dd847df4eeace14408))
* narrowly suppress false-positive shellcheck findings in tools/install ([d10705c](https://github.com/AraneaDev/Knossos-MCP/commit/d10705ca9104b863fd8d20114b877220cd660485))
* resolve rust compilation and update CI node version ([46bde69](https://github.com/AraneaDev/Knossos-MCP/commit/46bde69d2d1ef4067b300f832344f0c72c1a4252))
* **rust:** add missing docstring for current_impl_target ([fe18409](https://github.com/AraneaDev/Knossos-MCP/commit/fe184098cc79b1bd0c4fdc851819ac8859391693))
* **rust:** collapse repeated edges to the persistence identity ([6746ee3](https://github.com/AraneaDev/Knossos-MCP/commit/6746ee32ea1e61bd6f2137ecd4142b947346f869))
* **rust:** drop an unresolved qualified call target instead of guessing ([c9872df](https://github.com/AraneaDev/Knossos-MCP/commit/c9872df223925bb4f32d38bbf25e6ce6e0d8e4f8))
* **rust:** namespace local ids, stop duplicating mod nodes, drop dangling source edges ([f7bef9a](https://github.com/AraneaDev/Knossos-MCP/commit/f7bef9a43514b9d6c2cb21340b9e3bb7001860e7))
* **rust:** never trust a single-segment call target ([d938a23](https://github.com/AraneaDev/Knossos-MCP/commit/d938a23baa81f0b1ab58d4d568e49f5411542d67))
* **rust:** point imports edges at the module holding the symbol ([1495dbc](https://github.com/AraneaDev/Knossos-MCP/commit/1495dbcb5e9d5a438b03024afc773edc66539131))
* **rust:** poison colliding aliases and resolve `self` leaves correctly ([fa17396](https://github.com/AraneaDev/Knossos-MCP/commit/fa1739678fb23462c3216b8d071ca3c32fdf16ef))
* **rust:** refuse backslash, NUL, dot, and empty scan path segments ([e043be9](https://github.com/AraneaDev/Knossos-MCP/commit/e043be98bf9001bc24955b7da38945289766c099))
* **rust:** resolve calls against their own container, drop closure/fn-pointer phantoms ([fee34b8](https://github.com/AraneaDev/Knossos-MCP/commit/fee34b83dfc44e43c7de6a48885dc3e7e4bcba33))
* **rust:** resolve ExprStruct and Self inside impl blocks ([3751bd7](https://github.com/AraneaDev/Knossos-MCP/commit/3751bd77ebad82472003264caa57e035db01c8bf))
* **rust:** resolve impl self types through path_target ([2263433](https://github.com/AraneaDev/Knossos-MCP/commit/22634331f9b627c901436f54871d7f802c7cba97))
* **rust:** stop path_target guessing through a poisoned alias ([c0db4f8](https://github.com/AraneaDev/Knossos-MCP/commit/c0db4f805298d4475e204c1698f9b42ce33c80f2))

## [0.10.6](https://github.com/AraneaDev/Knossos-MCP/compare/v0.10.5...v0.10.6) (2026-08-18)


### Bug Fixes

* **docker:** upgrade runtime base packages so the CVE gate can clear ([09dfca7](https://github.com/AraneaDev/Knossos-MCP/commit/09dfca7b26136709d6ef136132e95dc2128a566c))

## [0.10.5](https://github.com/AraneaDev/Knossos-MCP/compare/v0.10.4...v0.10.5) (2026-08-10)


### Bug Fixes

* **discovery:** exclude the whole .knossos namespace from discovery ([93c0d23](https://github.com/AraneaDev/Knossos-MCP/commit/93c0d23aeff320254f0aaafadf1e5ca1a63b4f51))
* **discovery:** exclude the whole .knossos namespace from discovery ([4259bfd](https://github.com/AraneaDev/Knossos-MCP/commit/4259bfd57d7a4604e2d5cc82110781a98ce9ef40))

## [0.10.4](https://github.com/AraneaDev/Knossos-MCP/compare/v0.10.3...v0.10.4) (2026-08-09)


### Bug Fixes

* **docs:** stop link-checking git-ignored working-copy files ([53f9881](https://github.com/AraneaDev/Knossos-MCP/commit/53f988119df7337c7d9204a72402e117c249862a))
* **health:** count role-based dead-code exclusions and stop gates scanning git-ignored files ([aed63a4](https://github.com/AraneaDev/Knossos-MCP/commit/aed63a47dc1eb208b079e63b919030ef7f0d61a8))
* **health:** count role-based dead-code exclusions instead of dropping them silently ([548f41c](https://github.com/AraneaDev/Knossos-MCP/commit/548f41c3ad986e9dd6020b5f24003660bdf0315c))
* **health:** reconcile convention exclusions before counting them ([518165a](https://github.com/AraneaDev/Knossos-MCP/commit/518165a77f95445823b8c3f59eef06f8015f6e91))
* **mutation:** stop crediting the suite for an impossible break-level mutant ([cf02215](https://github.com/AraneaDev/Knossos-MCP/commit/cf02215acdcd689e9d63dffbda73967425dcfecf))
* **tools:** stop repository checks from inspecting git-ignored files ([4150f85](https://github.com/AraneaDev/Knossos-MCP/commit/4150f856e0a0d87d5eb17c0b5b85cce95791011f))
* **tools:** stop the git-ignore filter deadlocking on a large path list ([6a738a5](https://github.com/AraneaDev/Knossos-MCP/commit/6a738a50ffbc0b2c14fb3e29a77bd215f43a3055))

## [0.10.3](https://github.com/AraneaDev/Knossos-MCP/compare/v0.10.2...v0.10.3) (2026-08-08)


### Bug Fixes

* **boundary:** close the id-collision fallback and restore display names ([ea54646](https://github.com/AraneaDev/Knossos-MCP/commit/ea546468ee62e485fb2c59cce6054dedff84dace))
* **boundary:** key inferred rules by manifest path, not declared package name ([4f5aeae](https://github.com/AraneaDev/Knossos-MCP/commit/4f5aeae48e1f0164fb30e94260a44bb214cc518b))
* close 22 audit findings, including an RCE when scanning an untrusted repository ([ddcda6d](https://github.com/AraneaDev/Knossos-MCP/commit/ddcda6d4c696f05b4c258f5a3f726fd02fd6d95b))
* **discovery:** escape bracket classes and reject ignore patterns that cannot compile ([2744fbe](https://github.com/AraneaDev/Knossos-MCP/commit/2744fbe11c3c0bbe0f6fb75d8e51ba6df2cb258a))
* **discovery:** fix negated-class scanning and pin ignore-loader attribution ([a4cae77](https://github.com/AraneaDev/Knossos-MCP/commit/a4cae771cd3f6b35ab6dc9a38d5bf9da819845b3))
* **discovery:** match POSIX character classes in ignore patterns ([3efaaf9](https://github.com/AraneaDev/Knossos-MCP/commit/3efaaf97a7a390222622bc5a51f8e10f2799328d))
* **git:** bound the driver overrides by bytes and keep the spawn failure reason ([6d6c88f](https://github.com/AraneaDev/Knossos-MCP/commit/6d6c88f401f03b8bb524f30694b228f45ed5aca9))
* **git:** close include/worktree/equals-name bypasses in driver enumeration ([f42b5bf](https://github.com/AraneaDev/Knossos-MCP/commit/f42b5bf363fcbd9cbdb46d36d37c33c01ee5df94))
* **git:** drop the active commit when a malformed header follows it ([9bc1996](https://github.com/AraneaDev/Knossos-MCP/commit/9bc199619d0675ab6f09fd315c3baddfd243acca))
* **git:** force repository-controlled command hooks off and drop the inherited environment ([e6f99a2](https://github.com/AraneaDev/Knossos-MCP/commit/e6f99a249adfbca93c21da3f42da4fd4791e26c1))
* **git:** keep required filters from failing the diff and bound driver overrides ([9299fc2](https://github.com/AraneaDev/Knossos-MCP/commit/9299fc2802d6c650eeb053dfda36e5b9a109680d))
* **git:** neutralise repository-controlled filter/textconv drivers and make hardening opt-out explicit ([d764923](https://github.com/AraneaDev/Knossos-MCP/commit/d764923854f8b697063a987644c09171732885a2))
* **git:** order commit history by epoch instead of offset-bearing ISO strings ([f8b67c3](https://github.com/AraneaDev/Knossos-MCP/commit/f8b67c351dfacc87170385fc8fc995ce081807fa))
* **git:** refuse the two ambiguous shapes the runner used to resolve quietly ([0536f8d](https://github.com/AraneaDev/Knossos-MCP/commit/0536f8df667c76837b7d3a03c4b713ca76997889))
* **mcp:** normalise verbosity like every other string argument ([e46ed6f](https://github.com/AraneaDev/Knossos-MCP/commit/e46ed6f2ee318614f953a1a075498bd242d94af2))
* **mcp:** trim nested boundary and list-of-string arguments too ([c26c41d](https://github.com/AraneaDev/Knossos-MCP/commit/c26c41d81fa093fb0c81879c353ef779d2d9de21))
* **mcp:** trim string arguments and validate keys only against the catalog ([edc6ac8](https://github.com/AraneaDev/Knossos-MCP/commit/edc6ac8c0d9a25323c937ffba3d2573053ac25ff))
* **python:** report a parentless relative import instead of emitting py:module: ([0dddd07](https://github.com/AraneaDev/Knossos-MCP/commit/0dddd0784517ae153dd09e18650a198ca1b48f28))
* **query:** bound the staleness probe's enumeration, not just its stat calls ([aa1723b](https://github.com/AraneaDev/Knossos-MCP/commit/aa1723b73b754e994eeb658743bab332bca3f116))
* **query:** count the entries that appeared, not the directories that drifted ([66cf408](https://github.com/AraneaDev/Knossos-MCP/commit/66cf408c3eb18aaec9df1d77882586a28f89f941))
* **query:** remove unnecessary staleness grace period, fix flake in tests ([5ccc196](https://github.com/AraneaDev/Knossos-MCP/commit/5ccc1963ec58af68d34791ae34992801dcbeb5f7))
* **query:** report added, deleted, and rewound files as staleness ([10b1cbc](https://github.com/AraneaDev/Knossos-MCP/commit/10b1cbcfe307c0264b699ad126b2a62791304e35))
* **query:** stop a bounded architecture-health report reading as an exhaustive one ([b52895b](https://github.com/AraneaDev/Knossos-MCP/commit/b52895b450a7ce8f85ca920f92bc507fc089bcd8))
* **reconciliation:** drop an unresolvable edge target instead of aborting the scan ([21adb48](https://github.com/AraneaDev/Knossos-MCP/commit/21adb48d696c3c2888aec1ff619a59898c5286b6))
* **scan:** a file no worker emitted costs that file, not the scan ([12d640a](https://github.com/AraneaDev/Knossos-MCP/commit/12d640af3306aa16ba46aefaa008cf8881962af3))
* **scan:** bound scan batches by source bytes and per-language cost ([9ef059d](https://github.com/AraneaDev/Knossos-MCP/commit/9ef059dbedcea7c0d112fa45201080b0d8227149))
* **scan:** degrade a failed language worker to a diagnostic instead of failing the scan ([94acee5](https://github.com/AraneaDev/Knossos-MCP/commit/94acee516265c01f8ef1c50cef71f5f192803b82))
* **scan:** give a misattributed worker contribution its own diagnostic ([b0a78b1](https://github.com/AraneaDev/Knossos-MCP/commit/b0a78b1d0c583f3c1659bf5f7c3b783ce3054b75))
* **scan:** halve the scan batch budget on overflow instead of guessing it ([56fe87d](https://github.com/AraneaDev/Knossos-MCP/commit/56fe87db5ee119e10811ada66dd257841f2f88ac))
* **scanner:** reap worker descendants through a process group that actually exists ([214f1c4](https://github.com/AraneaDev/Knossos-MCP/commit/214f1c4099fa90e8bd41ac0ff8a5434e5674a52d))
* **scanner:** report a failed read during send() instead of reselecting it ([bd48b8e](https://github.com/AraneaDev/Knossos-MCP/commit/bd48b8e372ad2fb5f3846b84629e7b9a73b324e5))
* **scanner:** stop selecting on an exhausted stderr descriptor ([afa92ee](https://github.com/AraneaDev/Knossos-MCP/commit/afa92ee2b0170c5fcf0df0cec71ad9fcd8c7be74))
* **scanner:** stop the send loop spinning on an exhausted stdout ([5f31100](https://github.com/AraneaDev/Knossos-MCP/commit/5f3110081ab56985c5ad4a12fc5d36861568f3f9))
* **scan:** report every invalid contribution cardinality, not just the visible one ([73fc46c](https://github.com/AraneaDev/Knossos-MCP/commit/73fc46c84861e876932d3bd1d37df63077e96b97))
* **scan:** restamp the active scan when the fast path verifies the graph ([ae22368](https://github.com/AraneaDev/Knossos-MCP/commit/ae223686ae3e7cb222d28f6f8e7f75345d3649e5))
* **scan:** scope a reduced batch budget to the batch that overflowed ([9d1e313](https://github.com/AraneaDev/Knossos-MCP/commit/9d1e31348531433cdbaa4660ada631e1a7d69922))
* **scan:** send worker scan requests in bounded batches ([4944694](https://github.com/AraneaDev/Knossos-MCP/commit/4944694d13543eb844d89995bb0a88063202b5a2))
* **scan:** stop a degraded scan taking the no-change fast path ([d3e80fd](https://github.com/AraneaDev/Knossos-MCP/commit/d3e80fd873139452ba542f2444dce8c374800b3f))
* **tests:** make the liveness probe answer on hosts without procfs ([325bef3](https://github.com/AraneaDev/Knossos-MCP/commit/325bef39e9c82c09e0114c1207d001cc119dd682))


### Performance Improvements

* **mcp:** trim result payloads in proportion to the overage ([36858e9](https://github.com/AraneaDev/Knossos-MCP/commit/36858e948fbba8507f9e639d40ddd25bf8ee9aa9))
* **query:** stream traversal rows so timeout_ms bounds the fetch it documents ([02cd6d6](https://github.com/AraneaDev/Knossos-MCP/commit/02cd6d62418837a90874bf78fc3149030d378a7c))

## [0.10.2](https://github.com/AraneaDev/Knossos-MCP/compare/v0.10.1...v0.10.2) (2026-08-02)


### Bug Fixes

* **mcp:** drop project staleness from server-scoped tools ([4e094bc](https://github.com/AraneaDev/Knossos-MCP/commit/4e094bcaf2484667ce6108e3cc02873b0cc820bf))
* **query,scanner:** address the review findings on flow, tokens and reads ([d1c3cd3](https://github.com/AraneaDev/Knossos-MCP/commit/d1c3cd31ab99a2e237caf38b7b33842bc910737e))
* **query:** dedupe flow paths and exclude script modules from dead code ([1c68473](https://github.com/AraneaDev/Knossos-MCP/commit/1c684735fe2f3798bff964863b054072cdf60eeb))
* **query:** drop test components from the agent brief's entry points ([63eb105](https://github.com/AraneaDev/Knossos-MCP/commit/63eb105846a607be36ed8af114f01dc4bc2593cd))
* **query:** grow diagrams along relationships and draw one arrow per edge ([0d77ed3](https://github.com/AraneaDev/Knossos-MCP/commit/0d77ed3c0753636cfb4297516bab687095e10307))
* **query:** let the architecture context summary reclaim spare budget ([0ca844e](https://github.com/AraneaDev/Knossos-MCP/commit/0ca844ed21a1f969c7dcef06f082af603cf8b5c3))
* **query:** rank locations by relevance density and match identifier words ([a0afb61](https://github.com/AraneaDev/Knossos-MCP/commit/a0afb6113c04f86586cf576f24966243c8de19e9))
* **query:** report the true usage total when results are capped ([a08f282](https://github.com/AraneaDev/Knossos-MCP/commit/a08f28284ac25c18b09d2736bfee4af15e998e31))
* **query:** stop the quality gate counting entry scripts as unreferenced ([aa4a084](https://github.com/AraneaDev/Knossos-MCP/commit/aa4a084dba3d085f6874a5c7fb2f34c9e8ff80e7))
* **query:** treat a returned interface as the contract a factory satisfies ([57fb18d](https://github.com/AraneaDev/Knossos-MCP/commit/57fb18d286ca3d5aff77f7392df0b3945b15e81c))
* **reconciliation:** resolve deferred receivers through traits and ancestors ([36c9570](https://github.com/AraneaDev/Knossos-MCP/commit/36c9570f86b1dbfd8318161d88195aaaef61511d))
* scanner and query accuracy findings from real-project runs ([185deaa](https://github.com/AraneaDev/Knossos-MCP/commit/185deaa4ac705e080f798e7ee7309dacaec85320))
* **scanner:** attribute PHP file-scope calls to a file module node ([f082738](https://github.com/AraneaDev/Knossos-MCP/commit/f082738311cf812e574a2c1c1faf311ecaa8aad5))
* **scanner:** key React and Vue roles on JSX and imports, not file extension ([8f3824e](https://github.com/AraneaDev/Knossos-MCP/commit/8f3824e4155f74a500e3707e4b266cac7c93dd94))
* **scanner:** mark Python and TypeScript script modules executable ([413299c](https://github.com/AraneaDev/Knossos-MCP/commit/413299c9608e2e42d7ba14cbe3271635c7c02c8e))
* **scanner:** resolve unqualified function calls against their namespace ([ca7aff7](https://github.com/AraneaDev/Knossos-MCP/commit/ca7aff78a3ee11acd92e3a6a126e9596f6ecb6f0))
* **scanner:** spell the byte-order mark out instead of importing codecs ([4d7cb9a](https://github.com/AraneaDev/Knossos-MCP/commit/4d7cb9abb982d7ab63baf0e8ce48f202017c7d7e))

## [0.10.1](https://github.com/AraneaDev/Knossos-MCP/compare/v0.10.0...v0.10.1) (2026-08-02)


### Bug Fixes

* **scanner:** resolve Python calls through their receiver and load the TypeScript compiler lazily ([#32](https://github.com/AraneaDev/Knossos-MCP/issues/32)) ([e9793dc](https://github.com/AraneaDev/Knossos-MCP/commit/e9793dc4d126e9d2da0cd4c2d2b8b45aa73ea096))

## [0.10.0](https://github.com/AraneaDev/Knossos-MCP/compare/v0.9.1...v0.10.0) (2026-08-02)


### Features

* **scanner:** resolve calls on values a method in another file returned ([5881a70](https://github.com/AraneaDev/Knossos-MCP/commit/5881a70f921529f4bf7c402563f51ca3de629a13))
* **scanner:** resolve cross-file receivers, and fix what auditing Knossos with Knossos found ([076e3ec](https://github.com/AraneaDev/Knossos-MCP/commit/076e3ec8d1bb122a864264c14d94df709e761d32))


### Bug Fixes

* address review findings and cover the paths the floors caught ([f2fd27c](https://github.com/AraneaDev/Knossos-MCP/commit/f2fd27c367509bd7390e71ee3591a87b8da4d22c))
* address the second review round ([02ba8ae](https://github.com/AraneaDev/Knossos-MCP/commit/02ba8aeccfdecaddfc88c5e2d60b375a72abf131))
* **php-scanner:** resolve receivers typed by a method's declared return type ([6a5bb60](https://github.com/AraneaDev/Knossos-MCP/commit/6a5bb6048cca18af5812b944208d58f608a13a53))
* **query:** count quality-gate budgets over the components health reports ([c2c973b](https://github.com/AraneaDev/Knossos-MCP/commit/c2c973b14990cb236539ca637ae714f616fff1ac))
* **query:** scan every edge when the quality gate checks boundary policies ([008258b](https://github.com/AraneaDev/Knossos-MCP/commit/008258b6807cd6560435434d5db4dfb4a311f93f))
* **query:** stop the repository-wide boundary from hiding every cross-boundary edge ([4a46eb0](https://github.com/AraneaDev/Knossos-MCP/commit/4a46eb06219322946a6bcec78e74607d4ce612c5))
* **runtime:** treat supported runtime versions as floors, not ranges ([6e39b3a](https://github.com/AraneaDev/Knossos-MCP/commit/6e39b3a96e0f049ac93b496fb8f68a33462d2ce3))


### Performance Improvements

* **query:** stream the boundary-policy check instead of collecting its edges ([722b7cd](https://github.com/AraneaDev/Knossos-MCP/commit/722b7cd0e5db466a7c9aee4b3b3f1e411002f2d7))
* **reconciliation:** report the commit as a phase of its own ([f3fd82d](https://github.com/AraneaDev/Knossos-MCP/commit/f3fd82de6da767bac2fc56228e2bcdb0fedb8018))
* **reconciliation:** write the graph difference instead of rewriting the graph ([9f5662d](https://github.com/AraneaDev/Knossos-MCP/commit/9f5662de817d87d582daefea416f419d73c3c477))
* **store:** cap the write-ahead log so its peak size is not permanent ([7ab1fae](https://github.com/AraneaDev/Knossos-MCP/commit/7ab1fae71845b647af8c7ff41bac86cd8864d2bf))
* **store:** compress archived snapshots and add a vacuum action ([b13c210](https://github.com/AraneaDev/Knossos-MCP/commit/b13c2108375a131c7a8929851bcb4ab5168b9f28))
* **store:** compress the snapshot as it is read rather than building it whole ([a3136d3](https://github.com/AraneaDev/Knossos-MCP/commit/a3136d37974b39fd5923725b8fa9209a5df7deba))
* **store:** verify graph integrity once per rewrite instead of per statement ([a788f84](https://github.com/AraneaDev/Knossos-MCP/commit/a788f841d2442b9b7d260c7b3b7ee36e55a49d05))

## [0.9.1](https://github.com/AraneaDev/Knossos-MCP/compare/v0.9.0...v0.9.1) (2026-08-01)


### Bug Fixes

* **boundary:** keep external packages out of path-prefix boundaries ([3562cae](https://github.com/AraneaDev/Knossos-MCP/commit/3562cae40239faa7931bbeaa1c0075d5d6974bbb))
* **boundary:** keep external packages out of path-prefix boundaries ([f48f9e6](https://github.com/AraneaDev/Knossos-MCP/commit/f48f9e6503654d7081f27cf3e22682d66e19f03f))

## [0.9.0](https://github.com/AraneaDev/Knossos-MCP/compare/v0.8.0...v0.9.0) (2026-07-30)


### Features

* **quality:** enforce docstring coverage, and fix what running Knossos over Knossos found ([#26](https://github.com/AraneaDev/Knossos-MCP/issues/26)) ([4e7027a](https://github.com/AraneaDev/Knossos-MCP/commit/4e7027aa5b252333238f786c344cb09570c16d2e))

## [0.8.0](https://github.com/AraneaDev/Knossos-MCP/compare/v0.7.0...v0.8.0) (2026-07-30)


### Features

* **discovery:** resolve allowed roots per request so one install serves any project ([2e1f9cf](https://github.com/AraneaDev/Knossos-MCP/commit/2e1f9cf5ca72b9efaa77d137a68ebc429182f0d3))
* **mcp:** serve protocol revision 2026-07-28 alongside 2025-11-25 ([5bda580](https://github.com/AraneaDev/Knossos-MCP/commit/5bda5808bf5f67f5456ea94501a23a70685e8f6a))
* **mcp:** support protocol 2026-07-28 and serve any project from one install ([0886ed1](https://github.com/AraneaDev/Knossos-MCP/commit/0886ed10d36855ece88ae129485d48a213b7d4f3))


### Bug Fixes

* **discovery:** detect narrowed roots by content, and preserve a "/" grant ([0dde7d3](https://github.com/AraneaDev/Knossos-MCP/commit/0dde7d3b089ecdad23d5e0a2e5c5d61532f82134))
* **discovery:** one grant per directory, and safe JSON on non-UTF-8 paths ([9392f2b](https://github.com/AraneaDev/Knossos-MCP/commit/9392f2b28758fffe5795e5d368c22e7c4eaca88a))
* **quality:** check every interface contract, not only those one level deep ([156286d](https://github.com/AraneaDev/Knossos-MCP/commit/156286db8469d11cf4693be51ffd1396619f5284))
* **quality:** run the covered suite unprivileged, and stop the deliberate 500 printing a SQLSTATE ([f32716c](https://github.com/AraneaDev/Knossos-MCP/commit/f32716c1eaa504d12d1ace80f76959df52bae48b))
* **tests:** declare nullable test-helper parameters explicitly ([3451996](https://github.com/AraneaDev/Knossos-MCP/commit/3451996d44ba3ff5a64523903829cf0b066222fb))

## [0.7.0](https://github.com/AraneaDev/Knossos-MCP/compare/v0.6.0...v0.7.0) (2026-07-27)


### Features

* **classification:** tag manifest entry points so scripts stop reading as dead ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))


### Bug Fixes

* **classification:** drop unreachable guard and pin the path rules ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **cli:** render CLI diagnostics to an injectable stream ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **discovery:** keep a leading dot in a nested manifest's directory ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **docs-gate:** check every doc file instead of skipping an ignored path ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **health:** exclude contract declarations an implementation carries ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **health:** stop reporting engine-invoked members and tool config as dead ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **php-scanner:** edge calls on constructed and nullsafe receivers ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **quality:** fail on warnings so mutation scores stop reporting phantoms ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **query:** exclude constructors of referenced types from dead-code candidates ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **query:** stop reporting dead code from a truncated edge scan ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **reconciler:** resolve member references through used traits ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **reconciler:** resolve members through parents and interfaces ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **reconciler:** resolve type references to interface, enum, and trait declarations ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **review:** harden the PR title check and stop overclaiming it ([5058f25](https://github.com/AraneaDev/Knossos-MCP/commit/5058f253b471aa64aa06def368a69abac1200507))
* **scanner:** emit references for callables used as values ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **scanner:** reference the class named by PHP static access ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **tools:** anchor the API documentation gate to real interface declarations ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **tools:** bound chaos-loop's --timeoutMs below Node's timer limit ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **tools:** raise the MCP request timeout in chaos-loop ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **ts-worker:** free a program cache slot before building the next program ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))


### Build System

* **deps:** move the TypeScript worker to vitest 4 ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))
* **deps:** take the patched brace-expansion in the quality lockfile, clearing GHSA-mh99-v99m-4gvg ([#21](https://github.com/AraneaDev/Knossos-MCP/issues/21)) ([066d93f](https://github.com/AraneaDev/Knossos-MCP/commit/066d93fd5eda7b86d0341b00b42abec7dcd90bb8))


### Continuous Integration

* require conventional pull request titles ([3db958e](https://github.com/AraneaDev/Knossos-MCP/commit/3db958e015971a94771937436681701d8db5d655))

## [0.6.0](https://github.com/AraneaDev/Knossos-MCP/compare/v0.5.1...v0.6.0) (2026-07-24)


### Features

* **mcp:** token-efficient response envelopes for agents ([#18](https://github.com/AraneaDev/Knossos-MCP/issues/18)) ([e2e4060](https://github.com/AraneaDev/Knossos-MCP/commit/e2e406034311e14c99c7051b5afbc7ba6a27a63b))

## [0.5.1](https://github.com/AraneaDev/Knossos-MCP/compare/v0.5.0...v0.5.1) (2026-07-24)


### Bug Fixes

* **mcp:** keep idle stdio connections warm with ping keepalives ([#16](https://github.com/AraneaDev/Knossos-MCP/issues/16)) ([a6e1955](https://github.com/AraneaDev/Knossos-MCP/commit/a6e1955f9aac705b6bf88faeb8c45453ccd5c5d9))

## [0.5.0](https://github.com/AraneaDev/Knossos-MCP/compare/v0.4.0...v0.5.0) (2026-07-23)


### Features

* **reconcile:** report per-phase timings through scan stage metrics ([7a03322](https://github.com/AraneaDev/Knossos-MCP/commit/7a0332254c46d3a6827f5314ab57558962513e1d))
* **scan:** persist failed and cancelled scans (audit batch 10) ([703e4a6](https://github.com/AraneaDev/Knossos-MCP/commit/703e4a69ef9a934f19bcd5d8be6ac12e55863898))


### Bug Fixes

* **boundary:** keep merged suffix display-only; stable id from primary rule name ([76e97f9](https://github.com/AraneaDev/Knossos-MCP/commit/76e97f9a1529b728045efb832f1ee4b7ca3b7494))
* **boundary:** merge inferred rules sharing an identical matcher ([18c5e1c](https://github.com/AraneaDev/Knossos-MCP/commit/18c5e1ca9e467c565b7f00f46ab422dd28c4db54))
* **boundary:** only symbol-shaped nodes seed inferred prefix rules ([a3bbc23](https://github.com/AraneaDev/Knossos-MCP/commit/a3bbc23f0a21864e045c87a7dbc55bce8914a102))
* **bundle:** validate untrusted imports and torn exports (audit batch 8) ([cd0287a](https://github.com/AraneaDev/Knossos-MCP/commit/cd0287adb343e0ecd5b221cdd9c43a49d1722077))
* **ci:** copy workers/python/tests into quality image so pytest collects them ([6b88580](https://github.com/AraneaDev/Knossos-MCP/commit/6b885805ba620d37b2a7ca09c0b6ce246af8ed5a))
* **cli:** option validation, git/doctor robustness, boundary anchoring (audit batch 7) ([b46aef3](https://github.com/AraneaDev/Knossos-MCP/commit/b46aef3f802102c878d58c630aca5aa98f62fa1e))
* **flow:** expand class endpoints to contained members in explain_flow ([0d329a8](https://github.com/AraneaDev/Knossos-MCP/commit/0d329a8cfdfc248676e916ecbf95d9eacd3e87a6))
* **mcp:** transport resilience, session safety, protocol conformance (audit batch 3) ([76f2d5d](https://github.com/AraneaDev/Knossos-MCP/commit/76f2d5dc6752ac6cfe6316983fad4bd95addad94))
* **query:** correctness, truncation honesty, and bounded fan-out (audit batch 2) ([6b3ab0f](https://github.com/AraneaDev/Knossos-MCP/commit/6b3ab0ff10ad85018c832b25903b970d2568b2ea))
* reconcile branch with upstream 0.4.0 (post-rebase) ([2ef14a1](https://github.com/AraneaDev/Knossos-MCP/commit/2ef14a18a933485e0d80b1373fc9bfaf95679569))
* **reconcile:** scope duplicate-symbol warning to non-shared kinds, once per id ([5e5d2be](https://github.com/AraneaDev/Knossos-MCP/commit/5e5d2beee83eb1c8375643907aaf48af5891f1bc))
* **reconcile:** start prepare phase timer before pre-transaction work ([768d29f](https://github.com/AraneaDev/Knossos-MCP/commit/768d29f411623c2b13838de6c10a22a796258f69))
* resolve four findings from testing the MCP against itself ([1843aed](https://github.com/AraneaDev/Knossos-MCP/commit/1843aed2d77d1e1b192d42814f6cb575bec95874))
* **scan:** fast-path staleness, discovery resilience, lock safety (audit batch 4) ([2283e6e](https://github.com/AraneaDev/Knossos-MCP/commit/2283e6ee431e5aaa72749685072e3bb849baa7c5))
* **suggest:** filter stop words and short tokens with permissive fallback ([ed85ec3](https://github.com/AraneaDev/Knossos-MCP/commit/ed85ec3b89e085948f293cd02f197a6d4936cd58))
* **test:** inject KNOSSOS_PHP_COVERAGE_DIR into php worker via env wrapper ([d7b2e80](https://github.com/AraneaDev/Knossos-MCP/commit/d7b2e809dc45ee391a370496556cbd89fcfae38b))
* **test:** repair audit-batch permission/DB tests exposed by non-root run ([6ca24d9](https://github.com/AraneaDev/Knossos-MCP/commit/6ca24d9d1435f6ad917498f6aa9711a3204dd792))
* **worker:** resolve send/crash/orphan/watch defects (audit batch 5) ([925b525](https://github.com/AraneaDev/Knossos-MCP/commit/925b525d3417d76c7354453577a343227ca4570a))
* **workers:** harden PHP/TS/Python scanners against hostile source (audit batch 6) ([9fd352e](https://github.com/AraneaDev/Knossos-MCP/commit/9fd352ed1c920a563e5abfb713591fbb09f0bd60))


### Performance Improvements

* **reconcile:** batch fact inserts; remove dead deleteFactsByOwner ([df9d516](https://github.com/AraneaDev/Knossos-MCP/commit/df9d516bb5b5d1945c707fdd03e4f9d78e76afc9))
* **reconcile:** clear_graph — 24179ms -&gt; 541ms on self-scan ([3c23d02](https://github.com/AraneaDev/Knossos-MCP/commit/3c23d02530d16d33b3f24504354c049e0febecd8))
* **store:** fix O(N^2) reconciliation and harden transactions (audit batch 1) ([9541d98](https://github.com/AraneaDev/Knossos-MCP/commit/9541d983bee5d11c5aa9e78441b38384713b0b74))

## [0.4.0](https://github.com/AraneaDev/Knossos-MCP/compare/v0.3.0...v0.4.0) (2026-07-23)


### Features

* **config:** checked-in dead_code_suppressions honored by architecture_health ([45f9206](https://github.com/AraneaDev/Knossos-MCP/commit/45f920652c9d328e91bccc66e21d75d9d06ab008))
* **mcp:** advertise and serve per-project resources (summary, boundaries, agent brief) ([f2c203e](https://github.com/AraneaDev/Knossos-MCP/commit/f2c203e1a5a3f9773edf93bbe53c64b5bd0183df))
* **mcp:** boundary legend in compact responses and single dead-code uncertainty warning ([554deba](https://github.com/AraneaDev/Knossos-MCP/commit/554deba5b63f61789127a6dfc609cbdbe71e6f96))
* **mcp:** honor a max_chars result budget on every read tool ([69d72a3](https://github.com/AraneaDev/Knossos-MCP/commit/69d72a325baf379073685ab348ee4b88f62e72a9))
* **mcp:** opt-in refresh_if_stale rescans stale graphs before answering read tools ([2e6541c](https://github.com/AraneaDev/Knossos-MCP/commit/2e6541cee838a30626d5c0cb7b0163435e06a6ce))
* **mcp:** orient and review_diff prompts ([3f8e053](https://github.com/AraneaDev/Knossos-MCP/commit/3f8e0534ff32a392c2e9f175b8f9b858eec7c6e5))
* **query:** annotations shape dead-code candidates and component dossiers ([86544c6](https://github.com/AraneaDev/Knossos-MCP/commit/86544c6db797ad5f351dc7314082f5dba9a80bf0))
* **query:** architecture_context optionally inlines RootGuard-contained source snippets ([858f69c](https://github.com/AraneaDev/Knossos-MCP/commit/858f69c94ee5fd118603528bfbda196d7fb32ba5))
* **query:** exclude external and test-role nodes from hubs and hotspots by default ([bf72640](https://github.com/AraneaDev/Knossos-MCP/commit/bf726401f3d7caf5e6979a1dd662ceb8752e4a0a))
* **query:** exclude interface-implementing methods from dead-code candidates and demote external-hierarchy methods ([9b5e577](https://github.com/AraneaDev/Knossos-MCP/commit/9b5e577c158a70d520dea5c848696752733cb706))
* **query:** export_agent_brief renders a markdown orientation brief for agent memory files ([81b95f4](https://github.com/AraneaDev/Knossos-MCP/commit/81b95f431e635321b37b81ae06d4a042e4585596))
* **query:** list_usages returns every usage occurrence with file:line evidence ([73524f5](https://github.com/AraneaDev/Knossos-MCP/commit/73524f5773ed246c5add27591b9fc5c85347c8a4))
* **query:** review_diff composes change impact, policy, gate, and cycle review in one call ([491c63e](https://github.com/AraneaDev/Knossos-MCP/commit/491c63e2cfd036056a550c4974705c11c68ff6c2))
* **query:** test_impact ranks test files that statically exercise a change ([ce47325](https://github.com/AraneaDev/Knossos-MCP/commit/ce47325f9f5a083d71162234d867972df68b1259))
* **store:** durable component annotations with annotate_component and list_annotations tools ([34675b6](https://github.com/AraneaDev/Knossos-MCP/commit/34675b6780511c08fb231d01bbc5fe1f909b22db))


### Bug Fixes

* address CodeRabbit review on PR [#11](https://github.com/AraneaDev/Knossos-MCP/issues/11) ([1a7ab3c](https://github.com/AraneaDev/Knossos-MCP/commit/1a7ab3cdaca521b4eabb9f8c3dcb7533251319c1))
* **cli:** wire the git working-tree provider into review-diff ([884b89d](https://github.com/AraneaDev/Knossos-MCP/commit/884b89de2e3700d86231ae93f25e66e320426d41))
* **mcp:** enforce max_chars against the full serialized envelope and always surface unmet budgets ([5b32e33](https://github.com/AraneaDev/Knossos-MCP/commit/5b32e33db9c7368ae347415d1b05277ff6d896d7))
* **mcp:** express BoundaryLegend list narrowing to phpstan ([c517132](https://github.com/AraneaDev/Knossos-MCP/commit/c5171328cd500f537c8f221122d6c2e345e1cd50))
* **query:** make export_agent_brief max_chars a hard bound and prove section omission in tests ([fcc1e89](https://github.com/AraneaDev/Knossos-MCP/commit/fcc1e89bc7a4cee3f8ef575247de11f4e6714f0b))
* **query:** refuse non-positive start lines in source excerpts; scope README parity claim to tools ([c0ba8eb](https://github.com/AraneaDev/Knossos-MCP/commit/c0ba8eb1438e2401c66afec42f0c89e489d204e2))
* **query:** review_diff degrades cycle-scan failures and unions sub-check warnings and evidence ([f33e957](https://github.com/AraneaDev/Knossos-MCP/commit/f33e9578c877c6f3d6041d82df24187428894876))
* **query:** scope ancestor metadata lookup by project in inheritedMethodContext ([1ad3363](https://github.com/AraneaDev/Knossos-MCP/commit/1ad336352a8ab8ae58002c97acfebc226eb29fbc))
* scope node uniqueness by language and log MCP server lifecycle ([4d16cfc](https://github.com/AraneaDev/Knossos-MCP/commit/4d16cfc8189a94794e0acfb8c224155f94ae3ed9))
* **test:** assert VERSION against version.txt instead of a release-breaking literal ([317e762](https://github.com/AraneaDev/Knossos-MCP/commit/317e76256b16d93fd63348f909231643d6e62b8b))


### Performance Improvements

* **scan:** skip graph rebuild and snapshot archiving when an incremental scan changed nothing ([1b75700](https://github.com/AraneaDev/Knossos-MCP/commit/1b757000971e53a003dba828c7cf5789b4e0e68e))
* **store:** batch node and edge upserts during reconciliation ([f98f562](https://github.com/AraneaDev/Knossos-MCP/commit/f98f56224ac25c3552d1d4fa7a92bfd272411cd5))

## [0.3.0](https://github.com/AraneaDev/Knossos-MCP/compare/v0.2.0...v0.3.0) (2026-07-20)


### Features

* **quality:** add a PHPUnit suite and Infection mutation testing ([8edcea9](https://github.com/AraneaDev/Knossos-MCP/commit/8edcea9291e74c29c0067a3d9260650978827bd1))
* **quality:** add a PHPUnit suite and Infection mutation testing ([c7e939b](https://github.com/AraneaDev/Knossos-MCP/commit/c7e939b3625486e5111ad8a2ffc4fb1a0d104875))

## [0.2.0](https://github.com/AraneaDev/Knossos-MCP/compare/v0.1.0...v0.2.0) (2026-07-20)


### Features

* **docker:** add compose profiles for CLI, MCP stdio, and loopback HTTP ([b5cfa19](https://github.com/AraneaDev/Knossos-MCP/commit/b5cfa19e2224ab319d4bd4ec583edf1a17e27057))
* **envelope:** add optional staleness, next_steps, meta enrichment fields ([b63a610](https://github.com/AraneaDev/Knossos-MCP/commit/b63a6108a373461f7f1d3370fa62087d5ca510ec))
* **mcp:** add NextStepPlanner for per-tool follow-up suggestions ([f237d6a](https://github.com/AraneaDev/Knossos-MCP/commit/f237d6ab457daaacd8ab610702f601377244f9dd))
* **mcp:** add ResultEnricher composing staleness, next_steps, verbosity, meta ([d14c0ad](https://github.com/AraneaDev/Knossos-MCP/commit/d14c0ad318be948c2033010086e7f1546485311c))
* **mcp:** commit portable project-scoped stdio registration ([3971886](https://github.com/AraneaDev/Knossos-MCP/commit/3971886945aaf5964006d7f893d212634f336b7b))
* **mcp:** enrich all tool results with staleness, next_steps, verbosity, meta ([953a7d5](https://github.com/AraneaDev/Knossos-MCP/commit/953a7d51abeb20c742494293abf4e9359b485936))
* **mcp:** intent-first tool descriptions and verbosity input; regenerate reference ([129cc31](https://github.com/AraneaDev/Knossos-MCP/commit/129cc312915a88eb4511857d8134e91de4860f4f))
* **query:** add read-only StalenessProbe for project freshness ([a83882b](https://github.com/AraneaDev/Knossos-MCP/commit/a83882ba3e1188c06e469e5a7cc2d541f42c9563))


### Bug Fixes

* **ci:** build pcov from a checksum-pinned tarball instead of pecl ([eccae29](https://github.com/AraneaDev/Knossos-MCP/commit/eccae2965124250529435d4aaacb8da6dad0c96b))
* **ci:** build pcov from a checksum-pinned tarball instead of pecl ([b7e9055](https://github.com/AraneaDev/Knossos-MCP/commit/b7e9055b6a36bc2488a456615e0d6bc5f2d428c5))
* **ci:** drop the MCP Observatory link waiver now that the badge resolves ([77d3a54](https://github.com/AraneaDev/Knossos-MCP/commit/77d3a545da6de20facc8520091ee47256f8550bd))
* **ci:** drop the MCP Observatory link waiver now that the badge resolves ([b9cffd0](https://github.com/AraneaDev/Knossos-MCP/commit/b9cffd05c1c5144afb16693bbebca525d2e6a36f))
* **ci:** stop binding host paths from inside the quality container ([188e93b](https://github.com/AraneaDev/Knossos-MCP/commit/188e93b727aaaf80224714ba521159e0e61e86f4))
* **cli:** emit architecture-summary JSON payload once ([7b0a147](https://github.com/AraneaDev/Knossos-MCP/commit/7b0a1470ed4544dc6ac87d5f56ecab68212c0f52))
* **discovery:** exclude build output and mutation-test sandboxes ([7982e46](https://github.com/AraneaDev/Knossos-MCP/commit/7982e46e0a50107b378e5b573d5c1e999cb9460f))
* **docker:** copy compose file and MCP registration into the quality image ([c61ab10](https://github.com/AraneaDev/Knossos-MCP/commit/c61ab10be875ee40cbd10b1186d36732dc6a41f8))
* **docker:** install pinned compose plugin in quality stage ([de40f44](https://github.com/AraneaDev/Knossos-MCP/commit/de40f44115038e316829c9af992862170c083806))
* **docker:** purge build headers so the runtime image clears the CVE gate ([6170cb6](https://github.com/AraneaDev/Knossos-MCP/commit/6170cb6fa8be77ce32253c627de41799c312a674))
* **query:** exclude test modules from dead-code nomination ([ab8a5e9](https://github.com/AraneaDev/Knossos-MCP/commit/ab8a5e93886a2b23540dfd8215a80ea203b280fb))
* **query:** report dead-code reasons as unfound, not proven absent ([e2bfbd2](https://github.com/AraneaDev/Knossos-MCP/commit/e2bfbd2a35174389f2c5bb728a417672f390e4a4))
* **ts-scanner:** prevent OOM and timeouts on real TypeScript projects ([7dcbc56](https://github.com/AraneaDev/Knossos-MCP/commit/7dcbc56ad0d02e3c1bbc3da95629e4575c95ffaa))
