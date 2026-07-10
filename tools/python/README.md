# Bundled Python for fingerprint FMD

Fingerprint enrollment/login uses `fingerprint_dpfj_worker.py` to call DigitalPersona `dpfj.dll`. PHP looks here **first**:

```
htdocs/tools/python/python.exe
```

Copy this entire `tools/python/` folder when you deploy to a new server — no separate Python install required.

## One-time setup (per deployment package)

1. Download **Windows embeddable package (64-bit)** from [python.org/downloads](https://www.python.org/downloads/windows/)  
   (e.g. “Windows embeddable package (64-bit)” for Python 3.12.x or 3.13.x)

2. Extract the zip **into this folder** so you have:
   ```
   tools/python/python.exe
   tools/python/python313.dll   (version number matches your download)
   tools/python/python313._pth
   ...
   ```

3. Edit `python313._pth` (filename matches your version, e.g. `python312._pth` for 3.12) in this folder:
   ```
   python313.zip
   .
   import site
   ```
   The `import site` line is required so the embeddable build loads the standard library.

4. Verify from the project root (`htdocs`).

   **Command Prompt:**
   ```bat
   cd C:\xampp\htdocs
   echo {"op":"ping"}> ping.json
   tools\python\python.exe fingerprint_dpfj_worker.py ping.json
   del ping.json
   ```

   **PowerShell:**
   ```powershell
   cd C:\xampp\htdocs
   '{"op":"ping"}' | Set-Content -NoNewline ping.json
   .\tools\python\python.exe .\fingerprint_dpfj_worker.py .\ping.json
   Remove-Item ping.json
   ```

   You should see JSON with `"ok": true`.

5. Check from PHP (browser or CLI):
   ```
   /fingerprint_native_status.php
   ```
   Expect `"python_source": "bundled"` and `"available": true`.

## Overrides

| Priority | Setting |
|----------|---------|
| 1 | Environment variable `FP_PYTHON=C:\path\to\python.exe` |
| 2 | `python_bin` in `fingerprint_config.php` |
| 3 | **`tools/python/python.exe`** (default) |
| 4 | Common system paths, then `python` on PATH |

## Notes

- Use **64-bit** Python if XAMPP/Apache PHP is 64-bit.
- No pip packages are required — the worker uses only the standard library.
- `dpfj.dll` must still be present (U.are.U / DigitalPersona runtime, usually in `C:\Windows\System32\`).
