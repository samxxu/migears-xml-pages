# migears-xml-pages — Known Issues

> Summary of this module's issues. The items themselves are in [`issues/`](issues/README.md), one file
> per item: a front-matter header and a thread. This file is generated from them and can be rewritten at
> any time; edit an item, never this file.
>
> From the miGears Full-Module Code Review Report (6th round, 2026-10-01).

| | |
|---|---|
| Status | **Best state** |
| Size | src 450 lines (net) · 157 tests · 2 src files |

Legend — **P0** functional or security · **P1** documentation that fails when copied · **P2** robustness · **P3** metadata and docs

## At a glance

| | |
|---|---|
| Unsettled | P0 0 · P1 0 · P2 0 · P3 2 · other 0 |
| Settled | 4 of 6 |
| Waiting on the owner | _nothing_ |
| Waiting on the coordinator | _nothing_ |
| Waiting on the reviewer | `P3-5` |
| Deferred, owing nobody | `P3-3` |

| id | level | status | title |
|---|---|---|---|
| [`P2-1`](issues/P2-1.md) | P2 | **verified** | The entity pre-scan recognised a declaration only by an ASCII name … |
| [`P3-1`](issues/P3-1.md) | P3 | **verified** | Size against its own claim: `src/Compiler.php` is 710 lines for 'read … |
| [`P3-2`](issues/P3-2.md) | P3 | **verified** | A section whose name is empty after trimming gets different wording … |
| [`P3-3`](issues/P3-3.md) | P3 | **deferred** | The same semantic value is accepted differently by the two front ends: … |
| [`P3-4`](issues/P3-4.md) | P3 | **verified** | The entity pre-scan runs a regex over the raw source, so `<!-- <!ENTITY … |
| [`P3-5`](issues/P3-5.md) | P3 | **rejected** | The module's own `ATTR_NAME_PATTERN` did not receive the lookahead that … |

## Unclosed

What is left to do here: every item whose `status` is not `verified` or `closed`,
highest severity first. `waiting on` is the party who acts next, read from that status.

| | |
|---|---|
| Unclosed | **2** of 6 |
| By status | `rejected` 1 · `deferred` 1 |
| Waiting on | reviewer 1 · - 1 |

| level | item | status | waiting on | title |
|---|---|---|---|---|
| **P3** | [`P3-3`](issues/P3-3.md) | `deferred` | - | The same semantic value is accepted differently by the two front ends: … |
| **P3** | [`P3-5`](issues/P3-5.md) | `rejected` | reviewer | The module's own `ATTR_NAME_PATTERN` did not receive the lookahead that … |

## Verdict

The front end and its parity corpus are in good shape; the scalar-acceptance divergence from YAML is a recorded deliberate difference, not drift.

## Fixed since the last round

No item was awaiting a verdict; the record is consistent with the code — the entity pre-scan reads a comment- and CDATA-stripped copy and recognises a parameter entity’s declaration, the empty-section-name wording comes from the shared layer, and the parity harness genuinely compares both front ends (157 tests, none skipped, because the sibling is checked out beside it).

## Test gaps

FrontEndParityTest depends on yaml-pages being checked out beside it, so a standalone install skips it and only CI’s --fail-on-skipped catches that; the error corpus deliberately holds only shared-layer rules, so each parser’s own wording is outside the parity net.

## Verification protocol

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate`
- Warning/notice/deprecation/risky flags in `phpunit.xml.dist`: all four on
- A PHP warning counts as a test failure only where those flags are on; otherwise run `./vendor/bin/phpunit --fail-on-warning` explicitly.


---

# migears-xml-pages — 已知问题

> 本模块问题的概览。条目本体在 [`issues/`](issues/README.md)，一条目一文件：前置字段加讨论串。
> 本文件由条目生成，随时可以整段重写；请改条目，不要改本文件。
>
> 出自 miGears 全模块代码评审报告（6th round，2026-10-01）。

| | |
|---|---|
| 状态 | **状态最好** |
| 体量 | src 450 行（净）· 157 个用例 · 2 个源文件 |

级别说明 — **P0** 功能性或安全级 · **P1** 文档照抄即错 · **P2** 健壮性 · **P3** 元数据与文档

## 状态一览

| | |
|---|---|
| 未了结 | P0 0 · P1 0 · P2 0 · P3 2 · 其他 0 |
| 已了结 | 4 / 6 |
| 等模块主 | _无_ |
| 等协调人 | _无_ |
| 等评审方 | `P3-5` |
| 已暂缓，不欠谁 | `P3-3` |

| id | 级别 | 状态 | 标题 |
|---|---|---|---|
| [`P2-1`](issues/P2-1.md) | P2 | **verified** | 实体预扫描只按 ASCII 名称字符类识别声明，因此 `<!ENTITY é …>` 与 `<!ENTITY % pe …>` 被 … |
| [`P3-1`](issues/P3-1.md) | P3 | **verified** | 与自身声明相比偏大：src/Compiler.php 为「把 XML 读成数组 IR」用了 710 行，超过 spec 提到的约 500 … |
| [`P3-2`](issues/P3-2.md) | P3 | **verified** | section 名 trim 后为空的文案与 YAML 侧不同：XML 报 "sections[0]: section is missing … |
| [`P3-3`](issues/P3-3.md) | P3 | **deferred** | 同一语义值在两个前端的接受面不同：XML 走 HTML 布尔语义，required="1"、"yes"、"on"、""、"required" … |
| [`P3-4`](issues/P3-4.md) | P3 | **verified** | 实体预扫描对原始源码跑正则，因此 <!-- <!ENTITY x "y"> --> 也会被当作实体声明拒绝，尽管 libxml … |
| [`P3-5`](issues/P3-5.md) | P3 | **rejected** | 模块自己的 `ATTR_NAME_PATTERN` 没有收到 `migears-pages` `P3-4` … |

## 未关闭

本模块还剩什么要做：所有 `status` 不是 `verified` 或 `closed` 的条目，按严重度从高到低。
`waiting on` 是下一步该动手的一方，由其状态读出。

| | |
|---|---|
| 未关闭 | **2** / 6 |
| 按状态 | `rejected` 1 · `deferred` 1 |
| 等在谁 | 评审方 1 · - 1 |

| 级别 | 条目 | 状态 | 等在谁 | 标题 |
|---|---|---|---|---|
| **P3** | [`P3-3`](issues/P3-3.md) | `deferred` | - | 同一语义值在两个前端的接受面不同：XML 走 HTML 布尔语义，required="1"、"yes"、"on"、""、"required" … |
| **P3** | [`P3-5`](issues/P3-5.md) | `rejected` | 评审方 | 模块自己的 `ATTR_NAME_PATTERN` 没有收到 `migears-pages` `P3-4` … |

## 结论

该前端与对拍语料状态良好；与 YAML 的标量接受面分歧是已记录的刻意差异，不是漂移。

## 本轮已修复确认

No item was awaiting a verdict; the record is consistent with the code — the entity pre-scan reads a comment- and CDATA-stripped copy and recognises a parameter entity’s declaration, the empty-section-name wording comes from the shared layer, and the parity harness genuinely compares both front ends (157 tests, none skipped, because the sibling is checked out beside it).

## 测试盲区

FrontEndParityTest 依赖 yaml-pages 并排检出，单独安装时它会跳过，只有 CI 的 --fail-on-skipped 能兜住；错误语料刻意只含共享层规则，因此各解析器自己的文案不在对拍网内。

## 验证方式

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate`
- `phpunit.xml.dist` 中的 warning/notice/deprecation/risky 开关：四个全开
- 只有在上述开关打开时 PHP 警告才会导致套件失败；否则请显式加 `--fail-on-warning`。
