import os
import sys
from datetime import datetime

# --- SKIP SETTINGS ---
SKIP_SUBSTR = "archive"
SKIP_DIRS = {"node_modules", "vendor", ".git", "__pycache__", ".idea", ".vscode"}

# --- WHAT TO DUMP (WEB CODE ONLY) ---
CODE_EXTS = {
    # backend
    ".php", ".phtml", ".java", ".py", ".rb",
    # frontend
    ".js", ".ts", ".jsx", ".tsx",
    ".css", ".scss", ".sass", ".less",
    ".html", ".htm",
    # data / config
    ".json", ".yml", ".yaml",
    ".xml", ".sql",
    ".env", ".ini", ".conf",
    ".htaccess",
    # scripts
    ".sh", ".bat"
}

MAX_BYTES = 1_048_576  # 1MB


def should_skip_dir(name: str) -> bool:
    return (
        SKIP_SUBSTR in name.lower()
        or name in SKIP_DIRS
    )


def relpath(path: str, root: str) -> str:
    r = os.path.relpath(path, root)
    return "." if r == "." else r.replace("\\", "/")


def safe_read_text(path: str) -> str:
    for enc in ("utf-8", "utf-8-sig", "cp1251"):
        try:
            with open(path, "r", encoding=enc, errors="strict") as f:
                return f.read()
        except Exception:
            pass
    with open(path, "r", encoding="utf-8", errors="replace") as f:
        return f.read()


def walk_dirs_files(root: str):
    for cur, dirs, files in os.walk(root):
        dirs[:] = [d for d in dirs if not should_skip_dir(d)]
        yield cur, dirs, files


def main():
    root = sys.argv[1] if len(sys.argv) > 1 else os.getcwd()
    root = os.path.abspath(root)

    if not os.path.isdir(root):
        print(f"ERROR: root not found: {root}")
        sys.exit(2)

    out_dir = os.path.dirname(os.path.abspath(__file__))
    tree_path = os.path.join(out_dir, "tree.txt")
    list_path = os.path.join(out_dir, "filelist.txt")
    cont_path = os.path.join(out_dir, "contents.txt")

    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    dirs_visited = 0
    files_listed = 0
    code_dumped = 0
    code_skipped_size = 0

    # --- TREE ---
    tree_lines = [
        f'ROOT: "{root}"',
        f"GENERATED: {now}",
        "",
        "====== DIRECTORY STRUCTURE ======",
        "",
        "[+] .",
    ]

    dir_paths = []
    for cur, dirs, _files in walk_dirs_files(root):
        dirs_visited += 1
        dir_paths.append(cur)

    for d in sorted(dir_paths):
        if d == root:
            continue
        rel = relpath(d, root)
        depth = rel.count("/")
        indent = "  " * depth
        name = rel.split("/")[-1]
        tree_lines.append(f"{indent}[+] {name}")

    tree_lines.append("")
    tree_lines.append("====== STATS ======")
    tree_lines.append(f"Directories visited: {dirs_visited}")

    with open(tree_path, "w", encoding="utf-8") as f:
        f.write("\n".join(tree_lines))

    # --- FILELIST + CONTENTS ---
    with open(list_path, "w", encoding="utf-8") as fl, open(cont_path, "w", encoding="utf-8") as fc:

        fl.write(f'ROOT: "{root}"\nGENERATED: {now}\n\n====== FILE LIST (per folder) ======\n')

        fc.write(
            f'ROOT: "{root}"\nGENERATED: {now}\n\n====== CODE CONTENTS ======\n'
            f"Included extensions: {' '.join(sorted(CODE_EXTS))}\n"
            f"Max file size: {MAX_BYTES} bytes\n"
        )

        for cur, _dirs, files in walk_dirs_files(root):
            rel_folder = relpath(cur, root)
            fl.write(f"\n--- {rel_folder} ---\n")

            for name in sorted(files):
                full = os.path.join(cur, name)

                try:
                    st = os.stat(full)
                except Exception:
                    continue

                files_listed += 1
                mtime = datetime.fromtimestamp(st.st_mtime).strftime("%Y-%m-%d %H:%M:%S")

                fl.write(f"{mtime} | {st.st_size} bytes | {relpath(full, root)}\n")

                ext = os.path.splitext(name)[1].lower()

                if ext in CODE_EXTS:

                    if st.st_size > MAX_BYTES:
                        code_skipped_size += 1
                        fc.write("\n" + "=" * 70 + "\n")
                        fc.write(f"FILE: {full}\n")
                        fc.write(f"(skipped: too large - {st.st_size} bytes, limit {MAX_BYTES})\n")
                        fc.write("=" * 70 + "\n")
                        continue

                    code_dumped += 1
                    fc.write("\n" + "=" * 70 + "\n")
                    fc.write(f"FILE: {full}\n")
                    fc.write(f"MODIFIED: {mtime} | SIZE: {st.st_size} bytes\n")
                    fc.write("=" * 70 + "\n\n")

                    try:
                        fc.write(safe_read_text(full))
                    except Exception as e:
                        fc.write(f"\n[ERROR reading file: {e}]\n")

                    fc.write("\n")

        fl.write("\n\n====== STATS ======\n")
        fl.write(f"Files listed: {files_listed}\n")

        fc.write("\n\n====== STATS ======\n")
        fc.write(f"Code files dumped: {code_dumped}\n")
        fc.write(f"Skipped by size: {code_skipped_size}\n")

    print("Done:")
    print(" -", tree_path)
    print(" -", list_path)
    print(" -", cont_path)
    print(f"Stats: dirs={dirs_visited} files={files_listed} dumped={code_dumped} skipped={code_skipped_size}")


if __name__ == "__main__":
    main()
