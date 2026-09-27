# QTA UI/UX redesign pack

This directory is the durable brief for replacing the current portal presentation without changing its business behavior.

- [`claude-inspired-design-system.md`](claude-inspired-design-system.md) defines the visual language and tokens.
- [`ux-accessibility-standard.md`](ux-accessibility-standard.md) defines interaction, responsive, content, and accessibility requirements.
- [`current-state-audit.md`](current-state-audit.md) records the audit of branch `feature/raporti-qkl` at commit `69608ce`.
- [`redesign-execution-prompt.md`](redesign-execution-prompt.md) is the copy-ready implementation prompt.
- [`.codex/skills/qta-claude-ui/SKILL.md`](../../.codex/skills/qta-claude-ui/SKILL.md) routes coding agents to these rules.

## Evidence policy

Anthropic does not publish a complete, stable token specification for the Claude product UI. This pack therefore separates:

1. **Official product behavior** documented by Anthropic/Claude Help.
2. **Observed public UI values** measured from Claude's public web surface on 2026-09-25.
3. **Third-party measurements** used only as corroboration.
4. **QTA decisions** made to satisfy accessibility and the portal's administrative workflows.

The result is “Claude-inspired, QTA-owned,” not a claim that QTA uses Anthropic's official design system.
