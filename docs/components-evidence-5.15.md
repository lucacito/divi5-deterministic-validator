# Divi 5.15 Components: what the real builder saves

Evidence for the validator rules that accept component instances and the Slot module. Everything below was made in the real Divi 5.15.1 Visual Builder on the Docker dev site (2026-10-16, driven headless with Playwright) and read back from the database. Nothing is from memory. Live gate: `make verify-components`.

## How Divi stores a component

- A component is a **Divi Library item** (post type `et_pb_layout`) with the `scope` term `global` and a `layout_type` term (`section`, `row` or `module`). Its content is the saved blocks; every block carries a `syncNodeId` and a `localAttrsMap`. Library items are not page content: the plugin never edits them.
- A page that uses a component holds an **instance**: a `divi/global-layout` block with `globalModule` (the library item's id), `blockName` (the kind of block it stands for), `builderVersion`, `localAttrs` (the block's own attributes) and `localAttrOverridePaths`.

| What was saved as a component | What the page then holds | Fixture |
|---|---|---|
| a Section | `divi/global-layout` (`blockName: divi/section`) replacing the section; inside it the section's rows, columns and modules, each with `syncNodeId` + `localAttrOverridePaths` | `component-instance-plain-5.15.json` |
| a Row | `divi/global-layout` (`blockName: divi/row`) inside the section, wrapping the row's columns | `component-instance-row-5.15.json` |
| a Module (Heading) | a self-closing `divi/global-layout` (`blockName: divi/heading`, `localAttrs` = the heading's attributes) **in the column** | `component-instance-module-5.15.json` |
| a Section that contains a module component | the outer instance holds the inner `divi/global-layout` as a module in its column; the inner one gets a `syncNodeId` too | `component-nested-5.15.json` |

## The Slot

A Slot (`divi/global-slot`) is added in the builder's component editor ("Edit Component", then Add Element, Slot). In the component it is a self-closing block with a `slotId` (a UUID). On a page that uses the component the instance carries it too:

- left empty: `<!-- wp:divi/global-slot {"builderVersion":"5.15.1","slotId":"...","localAttrOverridePaths":[]} /-->` (`component-instance-slot-empty-5.15.json`);
- filled in the builder (a Button, then a Contact Form with its fields, added through the Slot's own add button): the slot becomes a paired block holding the local modules, which carry `localAttrOverridePaths` and **no** `syncNodeId`; the component itself is untouched (`component-instance-slot-filled-5.15.json`).

Divi's own PHP (`GlobalSlotUtils`) matches an instance's slot to the component's by `slotId` and ignores one without it.

## What the validator did before, and does now

Before (5.14 rules), on the real exports: the plain, row and section-instance pages passed; a **module** component (`divi/global-layout` in a column) and the **nested** one were refused (`UNEXPECTED_CHILD_TYPE`); every page with a **Slot** was refused (`UNKNOWN_MODULE_TYPE` + `UNEXPECTED_CHILD_TYPE`), so the AI could not edit it.

Now: `divi/global-layout` and `divi/global-slot` are allowed in a column; a Slot needs a non-empty string `slotId` (`MISSING_REQUIRED_FIELD` otherwise); a Slot holds modules and module components. Kept refused because the exports do not show them: a Slot directly in a row or section, a row or another Slot inside a Slot, a column saved as a component sitting in a row. A tried row insertion inside a Slot through the builder did not take.

## Render proof

`make verify-components` recreates the four library items exactly as the builder stored them (`fixtures/components/library-5.15.json`), points each fixture's `globalModule` at the new ids, saves each as a throwaway page and renders it with the real Divi: no errors or notices, the component's own text shown once, and for the filled slot the local Button and the contact form shown. All 6 fixtures pass on Divi 5.15.1.

## Finding on the way

Adding a module next to a Slot from an instance page (the plain "+" or "Add Element") did not put it in the Slot: it was added to the **component itself**, so every instance changed. Only the Slot's own add button puts a module in the Slot. An AI that adds a module in the same column as a component's Slot therefore edits the shared component. The guides and the component advisory say so.
