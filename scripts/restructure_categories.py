#!/usr/bin/env python3
import argparse
import csv
import html
import json
import re
import unicodedata
from collections import Counter, defaultdict
from pathlib import Path


MAIN_CATEGORIES = [
    {
        "name": "Iluminat si surse de lumina",
        "slug": "iluminat-si-surse-de-lumina",
        "description": "Corpuri de iluminat, surse de lumina, sisteme LED si iluminat de siguranta.",
    },
    {
        "name": "Cabluri, conductori si conectica",
        "slug": "cabluri-conductori-si-conectica",
        "description": "Cabluri, conductori, cleme, papuci, presetupe si accesorii de conectare.",
    },
    {
        "name": "Instalatii, trasee cabluri si scule",
        "slug": "instalatii-trasee-cabluri-si-scule",
        "description": "Doze, tuburi, canale, tavi, fixare, scule si materiale auxiliare.",
    },
    {
        "name": "Protectie electrica si comutatie",
        "slug": "protectie-electrica-si-comutatie",
        "description": "Intreruptoare, sigurante, descarcatoare, separatoare si comutatoare de sarcina.",
    },
    {
        "name": "Tablouri, dulapuri si distributie",
        "slug": "tablouri-dulapuri-si-distributie",
        "description": "Tablouri, dulapuri, cofrete, carcase, sisteme de bare si accesorii de distributie.",
    },
    {
        "name": "Aparataj terminal, prize si intrerupatoare",
        "slug": "aparataj-terminal-prize-si-intrerupatoare",
        "description": "Aparataj terminal, prize, intrerupatoare, rame, module si accesorii de montaj terminal.",
    },
    {
        "name": "Automatizari, control si masurare",
        "slug": "automatizari-control-si-masurare",
        "description": "Contactoare, relee, KNX, senzori, actionari, masurare, semnalizare si control industrial.",
    },
    {
        "name": "Retelistica, date si telecomunicatii",
        "slug": "retelistica-date-si-telecomunicatii",
        "description": "Cablare structurata, fibra optica, rack-uri, patching, SAT, telefonie si echipamente de retea.",
    },
    {
        "name": "Securitate, detectie si control acces",
        "slug": "securitate-detectie-si-control-acces",
        "description": "Supraveghere video, detectie incendiu/efractie, interfoane, control acces si sisteme speciale.",
    },
    {
        "name": "Energie, UPS si fotovoltaice",
        "slug": "energie-ups-si-fotovoltaice",
        "description": "UPS, baterii, PDU, e-mobility, management energie si sisteme fotovoltaice.",
    },
]


MAIN_BY_SLUG = {category["slug"]: category for category in MAIN_CATEGORIES}

EXACT_OVERRIDES = {
    "accesorii-2": ("instalatii-trasee-cabluri-si-scule", "exact: accesorii generale de instalare"),
    "accesorii-generale": ("tablouri-dulapuri-si-distributie", "exact: accesorii Modul 2000/distributie"),
    "cataloage": ("instalatii-trasee-cabluri-si-scule", "exact: documentatie si materiale auxiliare"),
    "fara-categorie": ("instalatii-trasee-cabluri-si-scule", "exact: categorie neutra"),
    "uncategorized": ("instalatii-trasee-cabluri-si-scule", "exact: categorie neutra"),
    "general": ("retelistica-date-si-telecomunicatii", "exact: telefonie/LSA/patching"),
    "liber": ("iluminat-si-surse-de-lumina", "exact: subarbore cu corpuri de iluminat"),
    "materiale-diverse": ("instalatii-trasee-cabluri-si-scule", "exact: materiale auxiliare"),
    "controlere-si-alimentari": ("iluminat-si-surse-de-lumina", "exact: controlere, drivere LED si dimmere"),
    "componente-de-sistem": ("iluminat-si-surse-de-lumina", "exact: componente iluminat de siguranta"),
    "grupuri-si-sisteme-cu-baterii-centrale": ("iluminat-si-surse-de-lumina", "exact: iluminat de siguranta cu baterii centrale"),
    "sisteme-cu-baterie-centrala-serie-maxicontrol-mcx": ("iluminat-si-surse-de-lumina", "exact: iluminat de siguranta cu baterii centrale"),
    "module-multipriza-it-meter-line": ("retelistica-date-si-telecomunicatii", "exact: PDU pentru rack/IT"),
    "electromechanical-swivel-levers-accessories": ("tablouri-dulapuri-si-distributie", "exact: accesorii dulapuri"),
    "power": ("energie-ups-si-fotovoltaice", "exact: PDU si UPS"),
    "climatizare-si-iluminat-pentru-dulapuri-electrice": ("tablouri-dulapuri-si-distributie", "exact: climatizare pentru dulapuri electrice"),
    "labeling-system-smartprintplus": ("cabluri-conductori-si-conectica", "exact: etichetare conductori si borne"),
    "module-suport-19-cu-plastron-si-sina-pentru-aparataj-modular": ("retelistica-date-si-telecomunicatii", "exact: suport 19 inch"),
    "profile-montanti-verticali": ("tablouri-dulapuri-si-distributie", "exact: accesorii structura dulap"),
    "digital-tech": ("retelistica-date-si-telecomunicatii", "exact: categorie IT"),
    "gadgets": ("retelistica-date-si-telecomunicatii", "exact: categorie IT"),
    "home-office": ("retelistica-date-si-telecomunicatii", "exact: categorie IT"),
    "innovative-appliances": ("retelistica-date-si-telecomunicatii", "exact: categorie IT"),
    "it-tech": ("retelistica-date-si-telecomunicatii", "exact: categorie IT"),
    "heaters": ("tablouri-dulapuri-si-distributie", "exact: incalzitoare dulapuri"),
    "accessories-2": ("instalatii-trasee-cabluri-si-scule", "exact: accesorii generale"),
    "accesorii-diverse": ("instalatii-trasee-cabluri-si-scule", "exact: accesorii diverse"),
    "billing-systems-austria": ("energie-ups-si-fotovoltaice", "exact: sisteme de billing/energie"),
    "unitati-cu-ventilatoare-19": ("retelistica-date-si-telecomunicatii", "exact: ventilatie rack 19 inch"),
    "smart-home": ("automatizari-control-si-masurare", "exact: smart home si automatizari"),
}


RULES = [
    (
        "securitate-detectie-si-control-acces",
        [
            "supraveghere video",
            "video surveillance",
            "cctv",
            "detectie efractie",
            "detectie incendiu",
            "detector de fum",
            "detectoare de fum",
            "control acces",
            "interfoane",
            "security",
            "apelare",
            "mediopt",
            "toalete",
            "instalatii de apel",
            "instalații de apel",
            "fingerscanner",
            "scanare de amprenta",
            "sisteme de control acces",
            "alarma",
        ],
    ),
    (
        "retelistica-date-si-telecomunicatii",
        [
            "retelistica",
            "retea",
            "retele",
            "cablare structurata",
            "date",
            "telecom",
            "telefonie",
            "fibra optica",
            "fibre optice",
            "fiber",
            "ftth",
            "rj45",
            "patch",
            "patchpanel",
            "patchcabluri",
            "server",
            "sorax",
            "rack",
            "sat",
            "coaxial",
            "antene",
            "multiswitch",
            "pigtail",
            "cuplori",
            "categoria om",
            "categoria os",
            "toolless",
            "s jack",
            "computer",
            "notebook",
            "gaming",
            "digital tech",
            "smart watches",
            "datendosen",
            "access point",
            "poe",
            "mediaconvertoare",
            "mediaconvertor",
            "lnb",
        ],
    ),
    (
        "energie-ups-si-fotovoltaice",
        [
            "fotovolta",
            "photovoltaic",
            "solar",
            "invertoare",
            "solax",
            "fronius",
            "saj",
            "victron",
            "panouri fotovoltaice",
            "ups",
            "baterii",
            "battery",
            "acumulatori",
            "pdu",
            "e mobility",
            "charging",
            "ladestationen",
            "statii de incarcare",
            "wallbox",
            "wallboxes",
            "storage systems",
            "management al energiei",
            "factorului de putere",
            "condensatoare",
            "alumero",
            "varista",
            "emobility",
            "incalzire radianta",
            "incalzire radiante",
        ],
    ),
    (
        "aparataj-terminal-prize-si-intrerupatoare",
        [
            "aparataj terminal",
            "prize",
            "fise cee",
            "fișe cee",
            "fise si prize",
            "fișe și prize",
            "flachenschalter",
            "elso",
            "visio",
            "termostate",
            "sonerie",
            "sonerii",
            "dimmere potentiometre",
        ],
    ),
    (
        "automatizari-control-si-masurare",
        [
            "automatiz",
            "knx",
            "contactor",
            "contactoare",
            "relee",
            "actuatoare",
            "senzori",
            "comanda",
            "control",
            "masura",
            "masurare",
            "măsur",
            "analizoare",
            "analyser",
            "analyzer",
            "measuring",
            "contoare",
            "transformatoare de curent",
            "current transformer",
            "ceasuri programabile",
            "crepusculare",
            "temporizatoare",
            "jaluzele",
            "pushbutton",
            "series mm",
            "mm series",
            "seria ma",
            "signal tower",
            "signal towers",
            "semnalizare",
            "monitoring",
            "sunturi",
            "split core transformers",
            "contacte auxiliare",
            "surse de alimentare",
            "transformatoare",
        ],
    ),
    (
        "iluminat-si-surse-de-lumina",
        [
            "corpuri de iluminat",
            "iluminat",
            "surse de lumina",
            "sursa de lumina",
            "benzi led",
            "spot",
            "spoturi",
            "construction site lighting",
            "self contained luminaires",
            "luminaires",
            "pictograme",
            "evacuare",
            "securo",
            "balasturi",
            "drivere led",
            "dimmere",
            "siruri luminoase",
            "sisteme de iluminat",
        ],
    ),
    (
        "protectie-electrica-si-comutatie",
        [
            "intreruptoare automate",
            "întreruptoare automate",
            "intreruptoare in aer",
            "intreruptoare compacte",
            "protectie",
            "protectia",
            "protecţie",
            "portfuz",
            "fuzibil",
            "fuzibili",
            "sigurante",
            "siguran",
            "descarcatoare",
            "supratensiune",
            "arc electric",
            "detectare a arcului",
            "separatoare",
            "comutatoare de sarcina",
            "comutatoare principale",
            "comutatoare de sursa",
            "oprire de urgenta",
            "reparatii",
            "switch disconnector",
            "breaker",
            "load switch",
            "neozed",
            "nh",
            "mc 1000vdc",
            "mcb",
            "rcd",
            "comutatoare cu came",
            "cam switch",
            "ram protection",
            "special switch",
            "tap changer",
            "rotary switch",
        ],
    ),
    (
        "tablouri-dulapuri-si-distributie",
        [
            "tablouri",
            "dulapuri",
            "dulap",
            "cofrete",
            "carcase",
            "cutii aplicate",
            "cutii",
            "cutii conexiuni",
            "cutii din poliester",
            "enclosure",
            "enclosures",
            "modul 2000",
            "modul 2500",
            "plastroane",
            "rame",
            "panouri",
            "placi frontale",
            "plăci frontale",
            "masti",
            "măști",
            "usi",
            "uși",
            "plinte",
            "cadre de montaj",
            "filterfans",
            "cooling",
            "ventilatie",
            "climatizare",
            "incuietori",
            "încuietori",
            "sisteme de bare",
            "bare colectoare",
            "sistemul de 60 mm",
            "sistemul fixlink",
            "modular chassis",
            "sikab",
            "stainless steel plinths",
            "outdoor rain canopies",
            "acil",
        ],
    ),
    (
        "cabluri-conductori-si-conectica",
        [
            "cablu",
            "cabluri",
            "conductoare",
            "conductor",
            "cleme",
            "terminale",
            "terminals",
            "bucse",
            "papuci",
            "coliere",
            "presetupe",
            "termocontractibile",
            "conectori",
            "cable ties",
            "cordon",
            "izolatoare",
            "bobine",
            "baret",
            "impamantare",
            "împământare",
        ],
    ),
    (
        "instalatii-trasee-cabluri-si-scule",
        [
            "canale",
            "tavi",
            "tăvi",
            "doze",
            "tuburi",
            "copex",
            "coturi",
            "mufe",
            "suruburi",
            "şuruburi",
            "dibluri",
            "cuie",
            "scule",
            "unelte",
            "burghie",
            "clesti",
            "surubelnite",
            "carote",
            "rulete",
            "cutite",
            "cuţite",
            "paste",
            "rasina",
            "răşină",
            "materiale",
            "montaj",
            "fixare",
            "garnituri",
        ],
    ),
]


def normalize(value):
    value = html.unescape(value or "")
    value = value.replace("\xa0", " ")
    value = unicodedata.normalize("NFKD", value)
    value = "".join(ch for ch in value if not unicodedata.combining(ch))
    value = value.lower()
    value = re.sub(r"[^a-z0-9]+", " ", value)
    return re.sub(r"\s+", " ", value).strip()


def to_int(value):
    try:
        return int(value or 0)
    except ValueError:
        return 0


def build_children(rows):
    children = defaultdict(list)
    for row in rows:
        children[row["parent_id"]].append(row)
    return children


def descendants(root, children):
    stack = [root]
    while stack:
        current = stack.pop()
        yield current
        stack.extend(reversed(children.get(current["term_id"], [])))


def classify_root(root, children):
    slug = root["slug"]
    subtree_rows = list(descendants(root, children))
    root_text = normalize(" ".join([root["name"], root["slug"], root["path"]]))
    subtree_text = normalize(" ".join(" ".join([row["name"], row["slug"], row["path"]]) for row in subtree_rows))

    if slug in EXACT_OVERRIDES:
        main_slug, reason = EXACT_OVERRIDES[slug]
        return main_slug, "high", reason

    normalized_rules = [
        (main_slug, [normalize(keyword) for keyword in keywords])
        for main_slug, keywords in RULES
    ]

    for main_slug, normalized_keywords in normalized_rules:
        for keyword in normalized_keywords:
            if keyword and keyword in root_text:
                return main_slug, "high", f"keyword in root: {keyword}"

    for main_slug, normalized_keywords in normalized_rules:
        for keyword in normalized_keywords:
            if keyword and keyword in subtree_text:
                return main_slug, "medium", f"keyword in subtree: {keyword}"

    return "instalatii-trasee-cabluri-si-scule", "low", "fallback: no strong keyword"


def resolve_root(row, rows_by_id):
    current = row
    seen = set()
    while current.get("parent_id"):
        parent_id = current["parent_id"]
        if parent_id in seen or parent_id not in rows_by_id:
            break
        seen.add(parent_id)
        current = rows_by_id[parent_id]
    return current


def prefixed_path(main_name, source_path):
    return f"{main_name} > {source_path}" if source_path else main_name


def write_csv(path, fieldnames, rows):
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames, extrasaction="ignore")
        writer.writeheader()
        writer.writerows(rows)


def main():
    parser = argparse.ArgumentParser(description="Restructure Schrack WooCommerce product categories under 10 main categories.")
    parser.add_argument("--input", default="/Users/simon/Downloads/schrack-product-categories-2026-07-03.csv")
    parser.add_argument("--output-dir", default="outputs/category-restructure-2026-07-03")
    args = parser.parse_args()

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

    root_mapping = {}
    root_mapping_rows = []
    for root in roots:
        root_rows = list(descendants(root, children))
        main_slug, confidence, reason = classify_root(root, children)
        main_category = MAIN_BY_SLUG[main_slug]
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

    output_rows_by_main = defaultdict(list)
    preview_rows = []
    for row in rows:
        root = resolve_root(row, rows_by_id)
        mapping = root_mapping[root["term_id"]]
        main_category = MAIN_BY_SLUG[mapping["main_slug"]]
        new_row = dict(row)
        old_path = row["path"]
        old_parent_path = row["parent_path"]

        new_row["path"] = prefixed_path(main_category["name"], old_path)
        if row["parent_id"]:
            new_row["parent_path"] = prefixed_path(main_category["name"], old_parent_path)
        else:
            new_row["parent_id"] = ""
            new_row["parent_slug"] = main_category["slug"]
            new_row["parent_path"] = main_category["name"]

        output_rows_by_main[mapping["main_slug"]].append((source_order[id(row)], new_row))
        preview_rows.append(
            {
                "term_id": row["term_id"],
                "name": row["name"],
                "slug": row["slug"],
                "main_category": main_category["name"],
                "original_path": old_path,
                "new_path": new_row["path"],
                "confidence": mapping["confidence"],
                "reason": mapping["reason"],
                "count": row["count"],
            }
        )

    final_rows = []
    summary = {
        "input_file": str(input_path),
        "source_rows": len(rows),
        "source_root_categories": len(roots),
        "output_rows": len(rows) + len(MAIN_CATEGORIES),
        "main_categories": [],
        "confidence_counts": Counter(item["confidence"] for item in root_mapping.values()),
        "default_rule_root_count": sum(1 for item in root_mapping.values() if item["confidence"] == "low"),
    }

    for menu_order, main_category in enumerate(MAIN_CATEGORIES):
        main_slug = main_category["slug"]
        mapped_root_items = [item for item in root_mapping.values() if item["main_slug"] == main_slug]
        main_root_row = {
            "term_id": "",
            "parent_id": "",
            "parent_slug": "",
            "parent_path": "",
            "path": main_category["name"],
            "name": main_category["name"],
            "slug": main_category["slug"],
            "description": main_category["description"],
            "display_type": "subcategories",
            "image_id": "",
            "image_url": "",
            "menu_order": str(menu_order),
            "count": str(sum(item["root_count"] for item in mapped_root_items)),
        }
        final_rows.append(main_root_row)
        final_rows.extend(row for _, row in sorted(output_rows_by_main.get(main_slug, []), key=lambda item: item[0]))
        summary["main_categories"].append(
            {
                "name": main_category["name"],
                "slug": main_slug,
                "description": main_category["description"],
                "root_categories": len(mapped_root_items),
                "category_rows": sum(item["node_count"] for item in mapped_root_items),
                "source_root_count_sum": sum(item["root_count"] for item in mapped_root_items),
                "source_all_count_sum": sum(item["subtree_count_sum"] for item in mapped_root_items),
            }
        )

    import_csv = output_dir / "schrack-product-categories-2026-07-03-10-main-import.csv"
    mapping_csv = output_dir / "schrack-category-root-mapping-2026-07-03.csv"
    preview_csv = output_dir / "schrack-category-restructured-preview-2026-07-03.csv"
    summary_json = output_dir / "schrack-category-restructure-summary-2026-07-03.json"
    workbook_json = output_dir / "schrack-category-workbook-data-2026-07-03.json"

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
        sorted(root_mapping_rows, key=lambda row: (MAIN_CATEGORIES.index(MAIN_BY_SLUG[row["main_slug"]]), row["root_name"].casefold())),
    )
    write_csv(
        preview_csv,
        ["term_id", "name", "slug", "main_category", "original_path", "new_path", "confidence", "reason", "count"],
        preview_rows,
    )
    summary_json.write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding="utf-8")
    workbook_json.write_text(
        json.dumps(
            {
                "summary": summary,
                "main_categories": summary["main_categories"],
                "root_mapping": sorted(
                    root_mapping_rows,
                    key=lambda row: (MAIN_CATEGORIES.index(MAIN_BY_SLUG[row["main_slug"]]), row["root_name"].casefold()),
                ),
                "restructured_preview": preview_rows,
                "import_rows": final_rows,
            },
            ensure_ascii=False,
            indent=2,
        ),
        encoding="utf-8",
    )

    print(json.dumps({
        "import_csv": str(import_csv),
        "mapping_csv": str(mapping_csv),
        "preview_csv": str(preview_csv),
        "summary_json": str(summary_json),
        "workbook_json": str(workbook_json),
        "source_rows": len(rows),
        "output_rows": len(final_rows),
        "source_roots": len(roots),
        "main_categories": len(MAIN_CATEGORIES),
        "confidence_counts": dict(summary["confidence_counts"]),
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
