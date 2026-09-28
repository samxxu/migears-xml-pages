# migears-xml-pages — Known Issues

> Summary of this module's issues. The items themselves are in [`issues/`](issues/README.md), one file
> per item: a front-matter header and a thread. This file is generated from them and can be rewritten at
> any time; edit an item, never this file.
>
> From the miGears Full-Module Code Review Report (5th round, 2026-09-28).

| | |
|---|---|
| Status | **Best state** |
| Size | src 443 lines (net) · 156 tests · 1 src file |

Legend — **P0** functional or security · **P1** documentation that fails when copied · **P2** robustness · **P3** metadata and docs

## At a glance

| | |
|---|---|
| Unsettled | P0 0 · P1 0 · P2 0 · P3 4 · other 0 |
| Settled | 0 of 4 |
| Waiting on the owner | `P3-1`, `P3-2`, `P3-3`, `P3-4` |
| Waiting on the reviewer | _nothing_ |
| Waiting on the coordinator | _nothing_ |
| Deferred, owing nobody | _nothing_ |

| id | level | status | title |
|---|---|---|---|
| [`P3-1`](issues/P3-1.md) | P3 | **open** | Size against its own claim: `src/Compiler.php` is 710 lines for 'read … |
| [`P3-2`](issues/P3-2.md) | P3 | **open** | A section whose name is empty after trimming gets different wording … |
| [`P3-3`](issues/P3-3.md) | P3 | **open** | The same semantic value is accepted differently by the two front ends: … |
| [`P3-4`](issues/P3-4.md) | P3 | **open** | The entity pre-scan runs a regex over the raw source, so `<!-- <!ENTITY … |

## Unclosed

What is left to do here: every item whose `status` is not `verified` or `closed`,
highest severity first. `waiting on` is the party who acts next, read from that status.

| | |
|---|---|
| Unclosed | **4** of 4 |
| By status | `open` 4 |
| Waiting on | owner 4 |

| level | item | status | waiting on | title |
|---|---|---|---|---|
| **P3** | [`P3-1`](issues/P3-1.md) | `open` | owner | Size against its own claim: `src/Compiler.php` is 710 lines for 'read … |
| **P3** | [`P3-2`](issues/P3-2.md) | `open` | owner | A section whose name is empty after trimming gets different wording … |
| **P3** | [`P3-3`](issues/P3-3.md) | `open` | owner | The same semantic value is accepted differently by the two front ends: … |
| **P3** | [`P3-4`](issues/P3-4.md) | `open` | owner | The entity pre-scan runs a regex over the raw source, so `<!-- <!ENTITY … |

## Verdict

The XML frontend for the shared pages compiler with thorough parity testing against the YAML frontend. All functional items resolved; only metadata and documentation-drift items remain.

## Fixed since the last round

All prior P3 items confirmed fixed or rejected: P3-1/P3-2 documentation drift addressed; P3-3 entity false-positive substantially mitigated by stripping comments/CDATA before scanning; P3-4 parity test corpus expanded.

## Test gaps

No test for XML with external entity references (XXE protection verification); no test for deeply nested XML element recursion; no test for invalid XML (parser error handling).

## Verification protocol

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate`
- Warning/notice/deprecation/risky flags in `phpunit.xml.dist`: all four on
- A PHP warning counts as a test failure only where those flags are on; otherwise run `./vendor/bin/phpunit --fail-on-warning` explicitly.


---

# migears-xml-pages — 已知问题

> 本模块问题的概览。条目本体在 [`issues/`](issues/README.md)，一条目一文件：前置字段加讨论串。
> 本文件由条目生成，随时可以整段重写；请改条目，不要改本文件。
>
> 出自 miGears 全模块代码评审报告（5th round，2026-09-28）。

| | |
|---|---|
| 状态 | **状态最好** |
| 体量 | src 443 行（净）· 156 个用例 · 1 个源文件 |

级别说明 — **P0** 功能性或安全级 · **P1** 文档照抄即错 · **P2** 健壮性 · **P3** 元数据与文档

## 状态一览

| | |
|---|---|
| 未了结 | P0 0 · P1 0 · P2 0 · P3 4 · 其他 0 |
| 已了结 | 0 / 4 |
| 等负责人 | `P3-1`, `P3-2`, `P3-3`, `P3-4` |
| 等评审方 | _无_ |
| 等协调人 | _无_ |
| 已暂缓，不欠谁 | _无_ |

| id | 级别 | 状态 | 标题 |
|---|---|---|---|
| [`P3-1`](issues/P3-1.md) | P3 | **open** | 与自身声明相比偏大：src/Compiler.php 为「把 XML 读成数组 IR」用了 710 行，超过 spec 提到的约 500 … |
| [`P3-2`](issues/P3-2.md) | P3 | **open** | section 名 trim 后为空的文案与 YAML 侧不同：XML 报 "sections[0]: section is missing … |
| [`P3-3`](issues/P3-3.md) | P3 | **open** | 同一语义值在两个前端的接受面不同：XML 走 HTML 布尔语义，required="1"、"yes"、"on"、""、"required" … |
| [`P3-4`](issues/P3-4.md) | P3 | **open** | 实体预扫描对原始源码跑正则，因此 <!-- <!ENTITY x "y"> --> 也会被当作实体声明拒绝，尽管 libxml … |

## 未关闭

本模块还剩什么要做：所有 `status` 不是 `verified` 或 `closed` 的条目，按严重度从高到低。
`waiting on` 是下一步该动手的一方，由其状态读出。

| | |
|---|---|
| 未关闭 | **4** / 4 |
| 按状态 | `open` 4 |
| 等在谁 | 负责人 4 |

| 级别 | 条目 | 状态 | 等在谁 | 标题 |
|---|---|---|---|---|
| **P3** | [`P3-1`](issues/P3-1.md) | `open` | 负责人 | 与自身声明相比偏大：src/Compiler.php 为「把 XML 读成数组 IR」用了 710 行，超过 spec 提到的约 500 … |
| **P3** | [`P3-2`](issues/P3-2.md) | `open` | 负责人 | section 名 trim 后为空的文案与 YAML 侧不同：XML 报 "sections[0]: section is missing … |
| **P3** | [`P3-3`](issues/P3-3.md) | `open` | 负责人 | 同一语义值在两个前端的接受面不同：XML 走 HTML 布尔语义，required="1"、"yes"、"on"、""、"required" … |
| **P3** | [`P3-4`](issues/P3-4.md) | `open` | 负责人 | 实体预扫描对原始源码跑正则，因此 <!-- <!ENTITY x "y"> --> 也会被当作实体声明拒绝，尽管 libxml … |

## 结论

共享页面编译器的 XML 前端，与 YAML 前端做了充分的对拍测试。所有功能性问题均已解决；仅剩元数据与文档漂移类问题。

## 本轮已修复确认

All prior P3 items confirmed fixed or rejected: P3-1/P3-2 documentation drift addressed; P3-3 entity false-positive substantially mitigated by stripping comments/CDATA before scanning; P3-4 parity test corpus expanded.

## 测试盲区

无 XML 外部实体引用测试（XXE 防护验证）；无深层嵌套 XML 元素递归测试；无非法 XML 测试（解析器错误处理）。

## 验证方式

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate`
- `phpunit.xml.dist` 中的 warning/notice/deprecation/risky 开关：四个全开
- 只有在上述开关打开时 PHP 警告才会导致套件失败；否则请显式加 `--fail-on-warning`。
