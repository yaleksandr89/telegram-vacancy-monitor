package main

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
)

func main() {
	exe, err := os.Executable()
	if err != nil {
		fmt.Fprintln(os.Stderr, "Не удалось определить папку приложения.")
		os.Exit(1)
	}
	root := filepath.Dir(exe)
	if !verifyPayload(root) {
		fmt.Fprintln(os.Stderr, "Файлы приложения отсутствуют или повреждены / App files are missing or corrupt.")
		os.Exit(1)
	}
	os.Exit(launch(root))
}

func verifyPayload(root string) bool {
	data, err := os.ReadFile(filepath.Join(root, "SHA256SUMS.json"))
	if err != nil {
		return false
	}
	files := map[string]string{}
	if json.Unmarshal(data, &files) != nil || len(files) < 10 {
		return false
	}
	for name, expected := range files {
		if filepath.IsAbs(name) || strings.Contains(name, "\\") || strings.Contains(name, ":") ||
			filepath.ToSlash(filepath.Clean(name)) != name || strings.HasPrefix(name, "../") {
			return false
		}
		path := filepath.Join(root, filepath.FromSlash(name))
		info, err := os.Lstat(path)
		if err != nil || !info.Mode().IsRegular() {
			return false
		}
		file, err := os.Open(path)
		if err != nil {
			return false
		}
		hash := sha256.New()
		_, err = io.Copy(hash, file)
		file.Close()
		if err != nil || hex.EncodeToString(hash.Sum(nil)) != expected {
			return false
		}
	}
	return true
}
