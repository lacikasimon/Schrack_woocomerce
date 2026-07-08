#!/usr/bin/env python3
import argparse
import csv
import importlib.util
import json
import math
import re
import unicodedata
from collections import Counter, defaultdict
from pathlib import Path


BASE_SCRIPT = Path(__file__).with_name("restructure_categories.py")
SPEC = importlib.util.spec_from_file_location("schrack_category_base", BASE_SCRIPT)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError(f"Could not load {BASE_SCRIPT}")

base = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(base)

PATH_SEPARATOR = " > "
VIRTUAL_GROUP_DISPLAY_TYPE = "subcategories"


def to_int(value):
    try:
        return int(value or 0)
    except ValueError:
        return 0


def split_path(path):
    return [
        part.strip()
        for part in re.split(r"\s*(?:>|/|\|)\s*", path or "")
        if part.strip()
    ]


def join_path(parts):
    return PATH_SEPARATOR.join(part for part in parts if part)


def normalize(value):
    value = unicodedata.normalize("NFKD", value or "")
    value = "".join(ch for ch in value if not unicodedata.combining(ch))
    value = value.lower()
    value = re.sub(r"[^a-z0-9]+", " ", value)
    return re.sub(r"\s+", " ", value).strip()


def sort_key(value):
    key = normalize(value)
    return key or value.casefold()


def range_marker(value):
    key = normalize(value)
    if not key:
        return "#"

    first = key[0]
    if first.isdigit():
        return "0-9"

    return first.upper()


def brief_name(value, limit=28):
    compact = re.sub(r"\s+", " ", value or "").strip()
    if len(compact) <= limit:
        return compact

    return compact[: limit - 3].rstrip() + "..."


def context_name(value, limit=96):
    compact = re.sub(r"\s+", " ", value or "").strip()
    if len(compact) <= limit:
        return compact

    head_length = max(16, int((limit - 5) * 0.62))
    tail_length = max(12, limit - 5 - head_length)

    return compact[:head_length].rstrip() + " ... " + compact[-tail_length:].lstrip()


def unique_group_label(parent, children, used_names, chunk_index):
    first = children[0]
    last = children[-1]
    first_marker = range_marker(first)
    last_marker = range_marker(last)

    if first_marker == last_marker:
        label = f"Subcategorii {first_marker}: {brief_name(first, 18)} - {brief_name(last, 18)}"
    else:
        label = f"Subcategorii {first_marker}-{last_marker}"

    candidate = label
    suffix = 2
    while candidate in used_names:
        candidate = f"{label} ({suffix})"
        suffix += 1

    return candidate


def is_virtual_group_name(value):
    return (value or "").startswith("Subcategorii ")


def chunk_children(child_names, max_children):
    ordered = sorted(child_names, key=sort_key)
    return [
        ordered[index : index + max_children]
        for index in range(0, len(ordered), max_children)
    ]


def direct_children(paths):
    children = defaultdict(set)

    for parts in paths.values():
        for index, child_name in enumerate(parts):
            parent = tuple(parts[:index])
            children[parent].add(child_name)

    return children


def balance_paths(paths, max_children):
    paths = {key: list(parts) for key, parts in paths.items()}
    inserted_groups = {}
    operations = []
    max_iterations = max(1000, len(paths) * 2)

    while True:
        if len(operations) > max_iterations:
            raise RuntimeError("Balancing did not converge.")

        parent_children = direct_children(paths)
        offenders = [
            (parent, sorted(children, key=sort_key))
            for parent, children in parent_children.items()
            if len(children) > max_children
        ]

        if not offenders:
            break

        parent, children = sorted(offenders, key=lambda item: (len(item[0]), -len(item[1]), join_path(item[0])))[0]
        parent_depth = len(parent)
        chunks = chunk_children(children, max_children)
        used_names = set(children)
        child_to_group = {}
        group_rows = []

        for chunk_index, chunk in enumerate(chunks, start=1):
            group_name = unique_group_label(parent, chunk, used_names, chunk_index)
            used_names.add(group_name)
            group_path = tuple(parent + (group_name,))
            inserted_groups[group_path] = {
                "name": group_name,
                "path_parts": list(group_path),
                "parent_parts": list(parent),
            }
            group_rows.append({"name": group_name, "children": len(chunk)})
            for child in chunk:
                child_to_group[child] = group_name

        for key, parts in list(paths.items()):
            if len(parts) <= parent_depth:
                continue

            if tuple(parts[:parent_depth]) != parent:
                continue

            child = parts[parent_depth]
            group = child_to_group.get(child)
            if not group:
                continue

            paths[key] = parts[:parent_depth] + [group] + parts[parent_depth:]

        operations.append(
            {
                "parent_path": join_path(parent) or "<root>",
                "original_children": len(children),
                "group_count": len(chunks),
                "groups": group_rows,
            }
        )

    return paths, inserted_groups, operations


def cap_paths_to_depth(paths, max_depth):
    if max_depth < 2:
        raise ValueError("max_depth must be at least 2")

    capped = {}
    changed = 0

    for key, parts in paths.items():
        if len(parts) <= max_depth:
            capped[key] = list(parts)
            continue

        prefix_length = 2 if len(parts) > 1 and is_virtual_group_name(parts[1]) else 1
        prefix_length = min(prefix_length, max_depth - 1)
        available_tail = max_depth - prefix_length
        remainder = parts[prefix_length:]

        if len(remainder) <= available_tail:
            capped[key] = list(parts)
            continue

        kept_tail_length = max(1, available_tail - 1)
        dropped = [part for part in remainder[:-kept_tail_length] if not is_virtual_group_name(part)]
        if not dropped:
            dropped = remainder[:-kept_tail_length]

        context = context_name(" - ".join(context_name(part, 96) for part in dropped), 180)
        capped[key] = parts[:prefix_length] + [context] + remainder[-kept_tail_length:]
        changed += 1

    return capped, changed


def balance_and_cap_paths(paths, max_children, max_depth):
    current = {key: list(parts) for key, parts in paths.items()}
    operations = []
    cap_operations = []
    max_iterations = max(100, len(current))

    for _ in range(max_iterations):
        current, _groups, balance_operations = balance_paths(current, max_children)
        operations.extend(balance_operations)

        current, capped_count = cap_paths_to_depth(current, max_depth)
        if capped_count:
            cap_operations.append({"capped_paths": capped_count, "max_depth": max_depth})

        if not balance_operations and 0 == capped_count:
            return current, operations, cap_operations

    raise RuntimeError("Balancing/depth capping did not converge.")


def derive_virtual_groups(paths):
    groups = {}

    for parts in paths.values():
        for index, part in enumerate(parts):
            if not is_virtual_group_name(part):
                continue

            group_path = tuple(parts[: index + 1])
            groups[group_path] = {
                "name": part,
                "path_parts": list(group_path),
                "parent_parts": list(parts[:index]),
            }

    return groups


def build_children(rows):
    children = defaultdict(list)
    for row in rows:
        children[row["parent_id"]].append(row)
    return children


def resolve_parent_slug(new_parent_parts, row, rows_by_id, main_by_name):
    if not new_parent_parts:
        return ""

    if len(new_parent_parts) == 1 and new_parent_parts[0] in main_by_name:
        return main_by_name[new_parent_parts[0]]["slug"]

    return ""


def virtual_group_row(group, count_by_prefix):
    parts = group["path_parts"]
    parent_parts = group["parent_parts"]

    return {
        "term_id": "",
        "parent_id": "",
        "parent_slug": "",
        "parent_path": join_path(parent_parts),
        "path": join_path(parts),
        "name": group["name"],
        "slug": "",
        "description": "Grup automat pentru navigare cu maximum 16 subcategorii directe.",
        "display_type": VIRTUAL_GROUP_DISPLAY_TYPE,
        "image_id": "",
        "image_url": "",
        "menu_order": "",
        "count": str(count_by_prefix.get(tuple(parts), 0)),
    }


def main_category_row(category, menu_order, count):
    return {
        "term_id": "",
        "parent_id": "",
        "parent_slug": "",
        "parent_path": "",
        "path": category["name"],
        "name": category["name"],
        "slug": category["slug"],
        "description": category["description"],
        "display_type": "subcategories",
        "image_id": "",
        "image_url": "",
        "menu_order": str(menu_order),
        "count": str(count),
    }


def write_csv(path, fieldnames, rows):
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames, extrasaction="ignore")
        writer.writeheader()
        writer.writerows(rows)


def output_sort_key(item):
    parts = item["path_parts"]
    return (len(parts), [sort_key(part) for part in parts], item["kind"])


def validate_output(rows, max_children):
    parent_children = defaultdict(set)
    duplicate_paths = Counter()
    max_depth = 0

    for row in rows:
        parts = split_path(row.get("path", ""))
        if not parts:
            continue

        duplicate_paths[join_path(parts)] += 1
        max_depth = max(max_depth, len(parts))

        for index, child in enumerate(parts):
            parent_children[tuple(parts[:index])].add(child)

    offenders = [
        {"parent_path": join_path(parent) or "<root>", "children": len(children)}
        for parent, children in parent_children.items()
        if len(children) > max_children
    ]
    duplicates = [
        {"path": path, "count": count}
        for path, count in duplicate_paths.items()
        if count > 1
    ]

    return {
        "max_children": max((len(children) for children in parent_children.values()), default=0),
        "max_depth": max_depth,
        "offenders": sorted(offenders, key=lambda item: (-item["children"], item["parent_path"])),
        "duplicate_paths": duplicates,
    }


def main():
    parser = argparse.ArgumentParser(
        description="Restructure Schrack WooCommerce product categories into readable groups with a direct-child limit."
    )
    parser.add_argument("--input", default="/Users/simon/Downloads/schrack-product-categories-2026-07-08.csv")
    parser.add_argument("--output-dir", default="outputs/category-restructure-2026-07-08")
    parser.add_argument("--date", default="2026-07-08")
    parser.add_argument("--max-children", type=int, default=16)
    parser.add_argument("--max-depth", type=int, default=6)
    args = parser.parse_args()

    if args.max_children < 2:
        raise SystemExit("--max-children must be at least 2")

    if args.max_depth < 2:
        raise SystemExit("--max-depth must be at least 2")

    input_path = Path(args.input)
    output_dir = Path(args.output_dir)
    output_dir.mkdir(parents=True, exist_ok=True)

    with input_path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        rows = list(reader)
        fieldnames = list(reader.fieldnames or [])

    if not rows:
        raise SystemExit("Input CSV has no data rows.")

    children = build_children(rows)
    rows_by_id = {row["term_id"]: row for row in rows if row["term_id"]}
    roots = [row for row in rows if not row["parent_id"]]
    source_order = {id(row): index for index, row in enumerate(rows)}
    main_by_slug = {category["slug"]: category for category in base.MAIN_CATEGORIES}
    main_by_name = {category["name"]: category for category in base.MAIN_CATEGORIES}

    root_mapping = {}
    root_mapping_rows = []

    for root in roots:
        root_rows = list(base.descendants(root, children))
        main_slug, confidence, reason = base.classify_root(root, children)
        main_category = main_by_slug[main_slug]
        root_mapping[root["term_id"]] = {
            "main_slug": main_slug,
            "main_name": main_category["name"],
            "confidence": confidence,
            "reason": reason,
            "root": root,
            "node_count": len(root_rows),
            "root_count": to_int(root["count"]),
            "subtree_count_sum": sum(to_int(row["count"]) for row in root_rows),
        }
        root_mapping_rows.append(
            {
                "main_category": main_category["name"],
                "main_slug": main_slug,
                "root_term_id": root["term_id"],
                "root_name": root["name"],
                "root_slug": root["slug"],
                "root_path": root["path"],
                "root_count": to_int(root["count"]),
                "subtree_rows": len(root_rows),
                "subtree_count_sum": sum(to_int(row["count"]) for row in root_rows),
                "confidence": confidence,
                "reason": reason,
            }
        )

    base_paths = {}
    row_roots = {}

    for row in rows:
        root = base.resolve_root(row, rows_by_id)
        row_roots[row["term_id"]] = root["term_id"]
        mapping = root_mapping[root["term_id"]]
        base_paths[row["term_id"]] = [mapping["main_name"]] + split_path(row["path"])

    balanced_paths, operations, cap_operations = balance_and_cap_paths(
        base_paths,
        args.max_children,
        args.max_depth,
    )
    inserted_groups = derive_virtual_groups(balanced_paths)

    count_by_prefix = defaultdict(int)
    for row in rows:
        row_count = to_int(row.get("count"))
        parts = balanced_paths[row["term_id"]]
        for index in range(1, len(parts) + 1):
            count_by_prefix[tuple(parts[:index])] += row_count

    output_items = []

    for menu_order, category in enumerate(base.MAIN_CATEGORIES):
        output_items.append(
            {
                "kind": "main",
                "path_parts": [category["name"]],
                "source_order": -1000 + menu_order,
                "row": main_category_row(
                    category,
                    menu_order,
                    count_by_prefix.get((category["name"],), 0),
                ),
            }
        )

    for group_path, group in inserted_groups.items():
        output_items.append(
            {
                "kind": "group",
                "path_parts": list(group_path),
                "source_order": -1,
                "row": virtual_group_row(group, count_by_prefix),
            }
        )

    preview_rows = []
    for row in rows:
        new_row = dict(row)
        parts = balanced_paths[row["term_id"]]
        parent_parts = parts[:-1]
        original_parent_id = row.get("parent_id", "")
        direct_parent_is_original = False

        if original_parent_id and original_parent_id in balanced_paths:
            direct_parent_is_original = tuple(parent_parts) == tuple(balanced_paths[original_parent_id])

        new_row["path"] = join_path(parts)
        new_row["parent_path"] = join_path(parent_parts)
        new_row["parent_id"] = original_parent_id if direct_parent_is_original else ""
        new_row["parent_slug"] = (
            rows_by_id[original_parent_id]["slug"]
            if direct_parent_is_original and original_parent_id in rows_by_id
            else resolve_parent_slug(parent_parts, row, rows_by_id, main_by_name)
        )

        output_items.append(
            {
                "kind": "category",
                "path_parts": parts,
                "source_order": source_order[id(row)],
                "row": new_row,
            }
        )

        mapping = root_mapping[row_roots[row["term_id"]]]
        preview_rows.append(
            {
                "term_id": row["term_id"],
                "name": row["name"],
                "slug": row["slug"],
                "main_category": mapping["main_name"],
                "original_path": row["path"],
                "new_path": new_row["path"],
                "confidence": mapping["confidence"],
                "reason": mapping["reason"],
                "count": row["count"],
            }
        )

    output_items.sort(key=lambda item: (item["path_parts"], item["kind"] != "main", item["source_order"]))
    final_rows = [item["row"] for item in output_items]

    validation = validate_output(final_rows, args.max_children)
    if validation["offenders"]:
        raise SystemExit(f"Validation failed: {json.dumps(validation['offenders'][:10], ensure_ascii=False)}")

    if validation["duplicate_paths"]:
        raise SystemExit(f"Duplicate paths detected: {json.dumps(validation['duplicate_paths'][:10], ensure_ascii=False)}")

    main_summaries = []
    for category in base.MAIN_CATEGORIES:
        main_slug = category["slug"]
        mapped_root_items = [item for item in root_mapping.values() if item["main_slug"] == main_slug]
        main_summaries.append(
            {
                "name": category["name"],
                "slug": main_slug,
                "description": category["description"],
                "root_categories": len(mapped_root_items),
                "category_rows": sum(item["node_count"] for item in mapped_root_items),
                "source_root_count_sum": sum(item["root_count"] for item in mapped_root_items),
                "source_all_count_sum": sum(item["subtree_count_sum"] for item in mapped_root_items),
            }
        )

    summary = {
        "input_file": str(input_path),
        "source_rows": len(rows),
        "source_root_categories": len(roots),
        "output_rows": len(final_rows),
        "main_categories": main_summaries,
        "virtual_group_rows": len(inserted_groups),
        "max_children_requested": args.max_children,
        "max_depth_requested": args.max_depth,
        "max_children_actual": validation["max_children"],
        "max_depth": validation["max_depth"],
        "balance_operations": operations,
        "depth_cap_operations": cap_operations,
        "confidence_counts": dict(Counter(item["confidence"] for item in root_mapping.values())),
        "default_rule_root_count": sum(1 for item in root_mapping.values() if item["confidence"] == "low"),
    }

    import_csv = output_dir / f"schrack-product-categories-{args.date}-balanced-import.csv"
    mapping_csv = output_dir / f"schrack-category-root-mapping-{args.date}.csv"
    preview_csv = output_dir / f"schrack-category-restructured-preview-{args.date}.csv"
    summary_json = output_dir / f"schrack-category-restructure-summary-{args.date}.json"

    write_csv(import_csv, fieldnames, final_rows)
    write_csv(
        mapping_csv,
        [
            "main_category",
            "main_slug",
            "root_term_id",
            "root_name",
            "root_slug",
            "root_path",
            "root_count",
            "subtree_rows",
            "subtree_count_sum",
            "confidence",
            "reason",
        ],
        sorted(
            root_mapping_rows,
            key=lambda row: (
                [category["slug"] for category in base.MAIN_CATEGORIES].index(row["main_slug"]),
                row["root_name"].casefold(),
            ),
        ),
    )
    write_csv(
        preview_csv,
        ["term_id", "name", "slug", "main_category", "original_path", "new_path", "confidence", "reason", "count"],
        preview_rows,
    )
    summary_json.write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding="utf-8")

    print(
        json.dumps(
            {
                "import_csv": str(import_csv),
                "mapping_csv": str(mapping_csv),
                "preview_csv": str(preview_csv),
                "summary_json": str(summary_json),
                "source_rows": len(rows),
                "output_rows": len(final_rows),
                "virtual_group_rows": len(inserted_groups),
                "source_roots": len(roots),
                "main_categories": len(base.MAIN_CATEGORIES),
                "max_children_actual": validation["max_children"],
                "max_depth": validation["max_depth"],
                "confidence_counts": summary["confidence_counts"],
            },
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
