package main

import (
	"fmt"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"syscall"
	"unsafe"
)

func launch(root string) int {
	kernel := syscall.NewLazyDLL("kernel32.dll")
	create := kernel.NewProc("CreateJobObjectW")
	set := kernel.NewProc("SetInformationJobObject")
	assign := kernel.NewProc("AssignProcessToJobObject")
	job, _, _ := create.Call(0, 0)
	if job == 0 {
		fmt.Fprintln(os.Stderr, "Не удалось создать группу процессов.")
		return 1
	}
	defer syscall.CloseHandle(syscall.Handle(job))
	// RU: KILL_ON_JOB_CLOSE не оставляет PHP после закрытия launcher; x64 layout.
	// EN: KILL_ON_JOB_CLOSE prevents PHP surviving launcher termination; x64 layout.
	var limits [144]byte
	*(*uint32)(unsafe.Pointer(&limits[16])) = 0x2000
	ok, _, _ := set.Call(job, 9, uintptr(unsafe.Pointer(&limits[0])), uintptr(len(limits)))
	if ok == 0 {
		return 1
	}
	// RU: Дочерняя консоль получает Ctrl+C; launcher ждёт её, сохраняя job handle.
	// EN: The child console receives Ctrl+C; launcher waits while retaining the job handle.
	interrupts := make(chan os.Signal, 1)
	signal.Notify(interrupts, os.Interrupt)
	defer signal.Stop(interrupts)
	shell := filepath.Join(os.Getenv("SystemRoot"), "System32", "WindowsPowerShell", "v1.0", "powershell.exe")
	cmd := exec.Command(shell, "-NoLogo", "-NoProfile", "-ExecutionPolicy", "Bypass", "-File", filepath.Join(root, "src", "ui", "windows", "main.ps1"))
	cmd.Dir = root
	cmd.Stdin, cmd.Stdout, cmd.Stderr = os.Stdin, os.Stdout, os.Stderr
	cmd.SysProcAttr = &syscall.SysProcAttr{CreationFlags: 0x4}
	if err := cmd.Start(); err != nil {
		fmt.Fprintln(os.Stderr, "Не удалось запустить меню.")
		return 1
	}
	process, err := syscall.OpenProcess(0x901, false, uint32(cmd.Process.Pid))
	if err != nil {
		cmd.Process.Kill()
		cmd.Wait()
		return 1
	}
	defer syscall.CloseHandle(process)
	ok, _, _ = assign.Call(job, uintptr(process))
	if ok == 0 {
		cmd.Process.Kill()
		cmd.Wait()
		fmt.Fprintln(os.Stderr, "Не удалось защитить дочерние процессы от потери родителя.")
		return 1
	}
	// RU: Дочерний процесс начинает исполнение только после помещения в job.
	// EN: The child starts executing only after joining the job.
	resume := syscall.NewLazyDLL("ntdll.dll").NewProc("NtResumeProcess")
	status, _, _ := resume.Call(uintptr(process))
	if status != 0 {
		cmd.Process.Kill()
		cmd.Wait()
		return 1
	}
	if err := cmd.Wait(); err != nil {
		select {
		case <-interrupts:
			return 130
		default:
		}
		if e, ok := err.(*exec.ExitError); ok {
			return e.ExitCode()
		}
		fmt.Fprintln(os.Stderr, "Не удалось запустить меню.")
		return 1
	}
	return 0
}
