# Divi 5 Layout JSON — Schema Documentation

> **Status: PENDING** — This document will be written after `make export-layouts`
> produces real exports from a running Divi 5 instance.

This document describes the actual observed structure of Divi 5 layout JSON,
derived empirically from real exports. It is the single source of truth for
the validator's rules. Nothing here is inferred from Divi 4, training data,
or documentation — only from real Divi 5 output.

---

## How this document will be built

1. Run `make up` (requires `divi/Divi.zip`)
2. Run `make export-layouts`
3. Inspect `fixtures/valid/` — read the raw JSON
4. Answer the questions in each section below
5. Update `src/SchemaRules.php` to match

---

## Questions to answer from real exports

### Storage location
- [ ] Is the layout in `post_content`, post meta, or a custom table?
- [ ] If post meta, what is the key name?
- [ ] If custom table, what is the table name and schema?

### Top-level structure
- [ ] What is the root object shape? (keys, types)
- [ ] Is there a version field? What format?

### Node / module structure
- [ ] What is the structural hierarchy? (sections → rows → columns → modules, or different?)
- [ ] What key identifies a node's type? (`type`, `name`, `blockType`, other?)
- [ ] What are all the known module types?
- [ ] What is the children/inner-content key name?

### Attribute groups
- [ ] What attribute groups exist on every module? (universal vs module-specific)
- [ ] Where does text/rich-content live?
- [ ] Are there responsive variants? How are they stored?

### Required fields (render-critical)
- [ ] Which fields, if missing, cause Divi to fatal or silently drop content?
- [ ] Which fields must be objects (not scalars)? (the deep-merge fatal case)

### Internal references
- [ ] Does Divi 5 use internal IDs for cross-references?
- [ ] Can a reference be orphaned? What does that look like?

---

## Observed structure (fill in after exports)

```
(paste annotated JSON excerpts here)
```

---

## Validator rules derived from this schema

| Violation Code | Condition | Path |
|---|---|---|
| `INVALID_JSON` | JSON does not parse | `$` |
| `WRONG_ROOT_TYPE` | Root is not an object | `$` |
| `EMPTY_DOCUMENT` | Input is empty string | `$` |
| *(TBD after Phase 3)* | | |
