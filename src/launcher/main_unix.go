//go:build !windows

package main

import (
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"syscall"
)

func launch(root string) int {
	env := []string{}
	for _, entry := range os.Environ() {
		key := strings.SplitN(entry, "=", 2)[0]
		switch key {
		case "PHP_BINARY", "PHPRC", "PHP_INI_SCAN_DIR", "LD_PRELOAD", "LD_LIBRARY_PATH", "VM_PLATFORM",
			"DYLD_LIBRARY_PATH", "DYLD_INSERT_LIBRARIES", "BASH_ENV", "ENV", "VM_PORTABLE",
			"OPENSSL_CONF", "OPENSSL_MODULES":
			continue
		}
		env = append(env, entry)
	}
	env = append(env, "VM_PORTABLE=1", "VM_PLATFORM="+runtime.GOOS)
	err := syscall.Exec("/bin/bash", []string{"/bin/bash", filepath.Join(root, "src/ui/gum/main.sh")}, env)
	fmt.Fprintln(os.Stderr, "Не удалось запустить меню:", err)
	return 1
}
