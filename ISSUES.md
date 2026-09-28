# migears-xml-pages — Known Issues / 已知问题

> Summary of this module's issues. The items themselves are in [`issues/`](issues/README.md), one file
> per item: a front-matter header and a thread. This file is generated from them and can be rewritten at
> any time; edit an item, never this file.
>
> 本模块问题的概览。条目本体在 [`issues/`](issues/README.md)，一条目一文件：前置字段加讨论串。
> 本文件由条目生成，随时可以整段重写；请改条目，不要改本文件。
>
> From the miGears Full-Module Code Review Report (4th round, 2026-09-27).

| | |
|---|---|
| Status / 状态 | **P0 cleared / P0 已清零** |
| Size / 体量 | src 721 lines (444 net) · 155 tests · 2 src files · plus spec.md |

Legend / 图例 — **P0** functional or security · **P1** documentation that fails when copied · **P2** robustness · **P3** metadata and docs
级别说明 — **P0** 功能性或安全级 · **P1** 文档照抄即错 · **P2** 健壮性 · **P3** 元数据与文档

## At a glance / 状态一览

| | |
|---|---|
| Items / 条目 | P0 0 · P1 0 · P2 0 · P3 4 · other 0 |
| Answered / 已回复 | 0 of 4 |
| Waiting / 等待回复 | `P3-1`, `P3-2`, `P3-3`, `P3-4` |

| id | level | status | title |
|---|---|---|---|
| [`P3-1`](issues/P3-1.md) | P3 | **open** | Size against its own claim: `src/Compiler.php` is 710 lines for 'read … |
| [`P3-2`](issues/P3-2.md) | P3 | **open** | A section whose name is empty after trimming gets different wording … |
| [`P3-3`](issues/P3-3.md) | P3 | **open** | The same semantic value is accepted differently by the two front ends: … |
| [`P3-4`](issues/P3-4.md) | P3 | **open** | The entity pre-scan runs a regex over the raw source, so `<!-- <!ENTITY … |

## Verdict / 结论

All three items are closed and the front end now has the same CI rigour as its siblings. The new findings are the cost of that hardening: a source-level regex pre-scan rejects entity declarations even inside comments and CDATA, and this front end and YAML now diverge on how they accept the same semantic values.

三项全部关闭，且这个前端现在有了与兄弟模块同等的 CI。新发现是这次加固的代价：源码级正则预扫描连注释与 CDATA 里的实体声明也一并拒绝，而且它和 YAML 对同一语义值的接受面出现分歧。

## Fixed since the last round / 本轮已修复确认

上一轮 3 项全部关闭：实体引用改为源码级拒绝（<!ENTITY 直接报错并在 spec 与 README 记录）、「零第三方依赖」改为如实表述（唯一依赖 migears/pages）、补上 CI（含 phpstan analyse 与 --fail-on-skipped，PHP 8.1–8.5 矩阵）。 

## Test gaps / 测试盲区

The parity corpus is thin: 3 page pairs and 4 error pairs, so most shared-layer rules (required scope, field scope, duplicate option/data/section, attr names, section trim) are not cross-checked and single-sided regressions stay invisible.

对拍语料偏薄：只有 3 对页面与 4 对错误，因此共享层的大多数规则（required 作用域、field 作用域、option/data/section 重复、属性名、section trim）不在对拍范围内，单边回归无法被发现。

## Verification protocol / 验证方式

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate`
- Warning/notice/deprecation/risky flags in `phpunit.xml.dist`: all four on
- A PHP warning counts as a test failure only where those flags are on; otherwise run `./vendor/bin/phpunit --fail-on-warning` explicitly.
- 只有在上述开关打开时 PHP 警告才会导致套件失败；否则请显式加 `--fail-on-warning`。
