#!/usr/bin/env python3
"""Build a compact bilingual search index from the static site pages."""
from __future__ import annotations

import json
import re
from html.parser import HTMLParser
from pathlib import Path

COPY_DIRS = ("fa", "pages", "fixtures", "sectors", "systems", "products")
SKIP_TAGS = {"script", "style", "noscript", "svg", "nav", "header", "footer"}
VOID_TAGS = {"area", "base", "br", "col", "embed", "hr", "img", "input", "link", "meta", "param", "source", "track", "wbr"}
SKIP_CLASSES = {"search-dialog", "mobile-menu", "site-header", "topbar"}


def clean(value: str) -> str:
    return re.sub(r"\s+", " ", value).strip()


class PageText(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.lang = "en"
        self.title_parts: list[str] = []
        self.description = ""
        self.keywords = ""
        self.headings: list[str] = []
        self.alt_text: list[str] = []
        self.body_parts: list[str] = []
        self.title_depth = 0
        self.heading_stack: list[tuple[str, list[str]]] = []
        self.ignore_stack: list[str] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        attrs_map = dict(attrs)
        classes = set((attrs_map.get("class") or "").split())
        if self.ignore_stack:
            if tag not in VOID_TAGS:
                self.ignore_stack.append(tag)
            return
        if tag in SKIP_TAGS or classes.intersection(SKIP_CLASSES):
            if tag not in VOID_TAGS:
                self.ignore_stack.append(tag)
            return
        if tag == "html" and attrs_map.get("lang"):
            self.lang = attrs_map["lang"].split("-")[0].lower()
        if tag == "meta":
            name = (attrs_map.get("name") or "").lower()
            content = clean(attrs_map.get("content") or "")
            if name == "description":
                self.description = content
            elif name == "keywords":
                self.keywords = content
        if tag == "title":
            self.title_depth += 1
        if tag in {"h1", "h2", "h3"}:
            self.heading_stack.append((tag, []))
        if tag == "img" and attrs_map.get("alt"):
            alt = clean(attrs_map["alt"])
            if alt:
                self.alt_text.append(alt)

    def handle_startendtag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        self.handle_starttag(tag, attrs)
        if tag not in VOID_TAGS:
            self.handle_endtag(tag)

    def handle_endtag(self, tag: str) -> None:
        if self.ignore_stack:
            for index in range(len(self.ignore_stack) - 1, -1, -1):
                if self.ignore_stack[index] == tag:
                    del self.ignore_stack[index:]
                    break
            return
        if tag == "title" and self.title_depth:
            self.title_depth -= 1
        if self.heading_stack and self.heading_stack[-1][0] == tag:
            _, parts = self.heading_stack.pop()
            value = clean(" ".join(parts))
            if value:
                self.headings.append(value)

    def handle_data(self, data: str) -> None:
        if self.ignore_stack:
            return
        value = clean(data)
        if value:
            self.body_parts.append(value)
        if self.title_depth:
            self.title_parts.append(data)
        if self.heading_stack:
            self.heading_stack[-1][1].append(data)

    def result(self, relative_path: str) -> dict[str, str] | None:
        title_from_meta = clean(" ".join(self.title_parts))
        title = self.headings[0] if self.headings else title_from_meta
        if not title:
            return None
        route = relative_path
        if route == "index.html":
            route = ""
        elif route == "fa/index.html":
            route = "fa/"
        body = " ".join(dict.fromkeys([*self.headings, self.description, self.keywords, *self.alt_text, *self.body_parts]))
        body = clean(body)[:4000]
        return {
            "title": title[:220],
            "url": route,
            "lang": "fa" if relative_path.startswith("fa/") or self.lang == "fa" else "en",
            "description": self.description[:360],
            "text": body,
        }


def build_search_index(root: Path) -> Path:
    paths = [root / "index.html"]
    for directory in COPY_DIRS:
        target = root / directory
        if target.is_dir():
            paths.extend(target.rglob("*.html"))

    entries: list[dict[str, str]] = []
    for path in sorted(set(paths)):
        if not path.is_file():
            continue
        parser = PageText()
        try:
            parser.feed(path.read_text(encoding="utf-8", errors="replace"))
            parser.close()
        except Exception as exc:  # Keep one malformed legacy page from blocking the whole index.
            print(f"Skipping {path.relative_to(root)}: {exc}")
            continue
        entry = parser.result(path.relative_to(root).as_posix())
        if entry:
            entries.append(entry)

    destination = root / "assets" / "js" / "search-index.json"
    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_text(json.dumps(entries, ensure_ascii=False, separators=(",", ":")) + "\n", encoding="utf-8")
    print(f"Built bilingual search index: {destination} ({len(entries)} pages, {destination.stat().st_size / 1024:.1f} KiB)")
    return destination


if __name__ == "__main__":
    build_search_index(Path(__file__).resolve().parents[1])
