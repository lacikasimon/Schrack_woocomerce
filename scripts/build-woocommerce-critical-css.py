#!/usr/bin/env python3
"""Compile native WooCommerce rules, preserving selectors and declaration order.

Development only: requires tinycss2 and cssselect2. Runtime requires neither Python
nor a CSS parser. Input must be the installed, unmodified woocommerce.css file.
"""
import argparse
import hashlib
import json
import re
from pathlib import Path

import tinycss2
from cssselect2 import parser

STATES = {
    "active", "selected", "disabled", "loading", "added",
    "woocommerce-invalid", "woocommerce-invalid-required-field",
    "woocommerce-invalid-email", "woocommerce-invalid-phone",
    "woocommerce-validated", "woocommerce-variation-add-to-cart-disabled",
    "woocommerce-variation-add-to-cart-enabled",
}
DYNAMIC = re.compile(r"woocommerce-(?:error|message|info|notices)|blockUI|select2|selectWoo", re.I)
ASSET = re.compile(r"^\.\./(?:fonts/WooCommerce\.(?:woff2?|ttf)|images/icons/(?:loader\.svg|credit-cards/(?:visa|mastercard|laser|diners|maestro|jcb|amex|discover)\.svg))$")
URL = re.compile(r"url\s*\(\s*(\"[^\"\r\n]*\"|'[^'\r\n]*'|[^()\s]*)\s*\)", re.I)


def assets(css):
    def replace(match):
        value = match[1].strip("\"'")
        if value.startswith("data:image/"):
            return match[0]
        if not ASSET.fullmatch(value):
            raise ValueError("Uninspected native asset: " + value)
        return 'url("__SCHRACK_WOO_ASSETS__/' + value[3:] + '")'
    return URL.sub(replace, css)


def classes(node):
    if isinstance(node, parser.CombinedSelector):
        return classes(node.left) | classes(node.right)
    if isinstance(node, parser.CompoundSelector):
        return set().union(*(classes(child) for child in node.simple_selectors))
    if isinstance(node, parser.ClassSelector):
        return set() if node.class_name in STATES else {node.class_name}
    # Negative, functional, attribute, ID and pseudo selectors impose no class
    # requirement. Ignoring these conditions keeps extra rules, never drops matches.
    return set()


def selectors(tokens):
    chunks = [[]]
    for token in tokens:
        if token.type == "literal" and token.value == ",":
            chunks.append([])
        else:
            chunks[-1].append(token)
    return [tinycss2.serialize(chunk).strip() for chunk in chunks]


def compile_rules(rules):
    result = []
    for rule in rules:
        if rule.type == "qualified-rule":
            entries = []
            for selector in selectors(rule.prelude):
                try:
                    requirements = classes(next(parser.parse(selector)).parsed_tree)
                except (parser.SelectorError, StopIteration):
                    requirements = set()
                if DYNAMIC.search(selector):
                    requirements = set()
                entries.append([selector, sorted(requirements)])
            result.append({"selectors": entries, "body": assets(tinycss2.serialize(rule.content))})
        elif rule.type == "at-rule" and rule.content is not None and rule.lower_at_keyword in {"media", "supports", "layer"}:
            result.append({"prefix": "@" + rule.at_keyword + " " + tinycss2.serialize(rule.prelude).strip(),
                           "children": compile_rules(tinycss2.parse_rule_list(rule.content, skip_comments=True, skip_whitespace=True))})
        elif rule.type == "at-rule":
            # Keep complete font faces, keyframes and other native at-rules.
            result.append({"raw": assets(rule.serialize())})
        else:
            raise ValueError("Unexpected native CSS rule: " + rule.type)
    return result


def main():
    args = argparse.ArgumentParser(description=__doc__)
    args.add_argument("source", type=Path)
    args.add_argument("output", type=Path)
    options = args.parse_args()
    raw = options.source.read_bytes()
    css = raw.decode("utf-8")
    if len(raw) > 98304 or re.search(r"@import|</style", css, re.I):
        raise ValueError("Native CSS is outside the inspected contract")
    manifest = {"format": 1, "source_sha256": hashlib.sha256(raw).hexdigest(),
                "license": "WooCommerce contributors: GNU General Public License v2 or later; https://github.com/woocommerce/woocommerce/blob/trunk/license.txt",
                "full": assets(css),
                "rules": compile_rules(tinycss2.parse_stylesheet(css, skip_comments=True, skip_whitespace=True))}
    options.output.parent.mkdir(parents=True, exist_ok=True)
    options.output.write_text(json.dumps(manifest, ensure_ascii=False, separators=(",", ":")) + "\n", encoding="utf-8")
    print(options.output, options.output.stat().st_size, "bytes; source", manifest["source_sha256"])


if __name__ == "__main__":
    main()
