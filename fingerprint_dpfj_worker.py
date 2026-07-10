#!/usr/bin/env python3
"""
DigitalPersona dpfj.dll worker (Windows stdcall via ctypes WinDLL).
Reads one JSON request from stdin, writes one JSON response to stdout.
"""
from __future__ import annotations

import base64
import json
import sys
import traceback
from ctypes import POINTER, WinDLL, c_int, c_uint, c_ubyte, byref

DPFJ_FMD_ANSI_378_2004 = 0x001B0001
DPFJ_CBEFF_ID = 51
MAX_FMD_SIZE = 1562
RC_MORE_DATA = 96075789


def _dll_path() -> str:
    import os

    env = os.environ.get("FP_NATIVE_DLL")
    if env and os.path.isfile(env):
        return env
    for path in (
        r"C:\Windows\System32\dpfj.dll",
        r"C:\Program Files\DigitalPersona\Bin\dpfj.dll",
    ):
        if os.path.isfile(path):
            return path
    return "dpfj.dll"


def _load_dpfj():
    dpfj = WinDLL(_dll_path())

    dpfj.dpfj_create_fmd_from_raw.argtypes = [
        POINTER(c_ubyte),
        c_uint,
        c_uint,
        c_uint,
        c_uint,
        c_int,
        c_uint,
        c_int,
        POINTER(c_ubyte),
        POINTER(c_uint),
    ]
    dpfj.dpfj_create_fmd_from_raw.restype = c_int

    dpfj.dpfj_start_enrollment.argtypes = [c_int]
    dpfj.dpfj_start_enrollment.restype = c_int

    dpfj.dpfj_add_to_enrollment.argtypes = [c_int, POINTER(c_ubyte), c_uint, c_uint]
    dpfj.dpfj_add_to_enrollment.restype = c_int

    dpfj.dpfj_create_enrollment_fmd.argtypes = [POINTER(c_ubyte), POINTER(c_uint)]
    dpfj.dpfj_create_enrollment_fmd.restype = c_int

    dpfj.dpfj_finish_enrollment.argtypes = []
    dpfj.dpfj_finish_enrollment.restype = c_int

    dpfj.dpfj_compare.argtypes = [
        c_int,
        POINTER(c_ubyte),
        c_uint,
        c_uint,
        c_int,
        POINTER(c_ubyte),
        c_uint,
        c_uint,
        POINTER(c_uint),
    ]
    dpfj.dpfj_compare.restype = c_int

    return dpfj


def _b64_to_bytes(data: str) -> bytes:
    s = data.strip().replace("-", "+").replace("_", "/")
    pad = len(s) % 4
    if pad:
        s += "=" * (4 - pad)
    return base64.b64decode(s)


def _b64url(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).decode("ascii").rstrip("=")


def _parse_pixels(scan: dict) -> tuple[bytes, int, int, int] | None:
    w = int(scan.get("width") or scan.get("iWidth") or 0)
    h = int(scan.get("height") or scan.get("iHeight") or 0)
    dpi = int(scan.get("dpi") or scan.get("iXdpi") or scan.get("iYdpi") or 500)
    raw = scan.get("data") or scan.get("Data") or ""
    if not isinstance(raw, str) or w < 8 or h < 8 or dpi < 100:
        return None
    try:
        pixels = _b64_to_bytes(raw)
    except Exception:
        return None
    need = w * h
    if len(pixels) < need:
        return None
    return pixels[:need], w, h, dpi


def create_fmd_from_raw(dpfj, scan: dict, finger_pos: int = 0) -> bytes | None:
    parsed = _parse_pixels(scan)
    if parsed is None:
        return None
    pixels, w, h, dpi = parsed
    image_size = w * h

    img_buf = (c_ubyte * image_size).from_buffer_copy(pixels)
    out_buf = (c_ubyte * MAX_FMD_SIZE)()
    out_size = c_uint(MAX_FMD_SIZE)

    rc = dpfj.dpfj_create_fmd_from_raw(
        img_buf,
        image_size,
        w,
        h,
        dpi,
        finger_pos,
        DPFJ_CBEFF_ID,
        DPFJ_FMD_ANSI_378_2004,
        out_buf,
        byref(out_size),
    )
    if rc != 0 or out_size.value == 0 or out_size.value > MAX_FMD_SIZE:
        return None
    return bytes(out_buf[: out_size.value])


def build_enrollment_fmd(dpfj, scans: list, finger_pos: int = 0) -> bytes | None:
    if len(scans) < 2:
        return None

    try:
        dpfj.dpfj_finish_enrollment()
    except Exception:
        pass

    if dpfj.dpfj_start_enrollment(DPFJ_FMD_ANSI_378_2004) != 0:
        return None

    added = 0
    for scan in scans:
        fmd = create_fmd_from_raw(dpfj, scan, finger_pos)
        if fmd is None:
            continue
        fmd_buf = (c_ubyte * len(fmd)).from_buffer_copy(fmd)
        rc = dpfj.dpfj_add_to_enrollment(
            DPFJ_FMD_ANSI_378_2004, fmd_buf, len(fmd), 0
        )
        if rc in (0, RC_MORE_DATA):
            added += 1

    if added < 2:
        dpfj.dpfj_finish_enrollment()
        return None

    out_buf = (c_ubyte * MAX_FMD_SIZE)()
    out_size = c_uint(MAX_FMD_SIZE)
    create_rc = dpfj.dpfj_create_enrollment_fmd(out_buf, byref(out_size))
    dpfj.dpfj_finish_enrollment()

    if create_rc != 0 or out_size.value == 0:
        return None
    return bytes(out_buf[: out_size.value])


def compare_fmd(dpfj, probe_b64: str, stored_b64: str) -> int | None:
    try:
        probe = _b64_to_bytes(probe_b64)
        stored = _b64_to_bytes(stored_b64)
    except Exception:
        return None
    if len(probe) < 16 or len(stored) < 16:
        return None
    if len(probe) > MAX_FMD_SIZE or len(stored) > MAX_FMD_SIZE:
        return None

    p_buf = (c_ubyte * len(probe)).from_buffer_copy(probe)
    s_buf = (c_ubyte * len(stored)).from_buffer_copy(stored)
    score = c_uint(0)
    rc = dpfj.dpfj_compare(
        DPFJ_FMD_ANSI_378_2004,
        p_buf,
        len(probe),
        0,
        DPFJ_FMD_ANSI_378_2004,
        s_buf,
        len(stored),
        0,
        byref(score),
    )
    if rc != 0:
        return None
    return int(score.value)


def handle(req: dict) -> dict:
    op = req.get("op")
    if op == "ping":
        return {"ok": True, "engine": "python_ctypes", "dll": _dll_path()}

    dpfj = _load_dpfj()

    if op == "create_fmd_from_raw":
        scan = req.get("scan") or {}
        finger_pos = int(req.get("finger_pos") or 0)
        fmd = create_fmd_from_raw(dpfj, scan, finger_pos)
        if fmd is None:
            return {"ok": False, "error": "create_fmd_from_raw_failed"}
        return {"ok": True, "fmd": _b64url(fmd)}

    if op == "build_enrollment_fmd":
        scans = req.get("scans") or []
        finger_pos = int(req.get("finger_pos") or 0)
        fmd = build_enrollment_fmd(dpfj, scans, finger_pos)
        if fmd is None:
            return {"ok": False, "error": "build_enrollment_fmd_failed"}
        return {"ok": True, "fmd": _b64url(fmd)}

    if op == "compare":
        score = compare_fmd(dpfj, req.get("probe") or "", req.get("stored") or "")
        if score is None:
            return {"ok": False, "error": "compare_failed"}
        return {"ok": True, "score": score}

    if op == "compare_batch":
        probe = req.get("probe") or ""
        stored_list = req.get("stored") or []
        if not isinstance(stored_list, list) or probe == "":
            return {"ok": False, "error": "compare_batch_invalid"}
        scores = []
        for stored in stored_list:
            if not isinstance(stored, str) or stored == "":
                scores.append(None)
                continue
            scores.append(compare_fmd(dpfj, probe, stored))
        return {"ok": True, "scores": scores}

    if op == "verify_scan":
        scan = req.get("scan") or {}
        stored_list = req.get("stored") or []
        finger_positions = req.get("finger_positions") or [0, 7, 8]
        if not isinstance(stored_list, list):
            return {"ok": False, "error": "verify_scan_invalid"}
        best_raw = None
        best_fmd = None
        best_pos = 0
        best_scores = None
        for finger_pos in finger_positions:
            try:
                pos = int(finger_pos)
            except (TypeError, ValueError):
                continue
            fmd = create_fmd_from_raw(dpfj, scan, pos)
            if fmd is None:
                continue
            fmd_b64 = _b64url(fmd)
            scores = []
            pos_best_raw = None
            for stored in stored_list:
                if not isinstance(stored, str) or not stored:
                    scores.append(None)
                    continue
                sc = compare_fmd(dpfj, fmd_b64, stored)
                scores.append(sc)
                if sc is not None:
                    pos_best_raw = sc if pos_best_raw is None else min(pos_best_raw, sc)
            if pos_best_raw is None:
                continue
            if best_raw is None or pos_best_raw < best_raw:
                best_raw = pos_best_raw
                best_fmd = fmd_b64
                best_pos = pos
                best_scores = scores
        if best_fmd is None or best_scores is None:
            return {"ok": False, "error": "verify_scan_failed"}
        return {
            "ok": True,
            "fmd": best_fmd,
            "finger_pos": best_pos,
            "scores": best_scores,
            "best_raw_score": best_raw,
        }

    return {"ok": False, "error": "unknown_op"}


def main() -> int:
    try:
        if len(sys.argv) > 1:
            with open(sys.argv[1], "r", encoding="utf-8-sig") as fh:
                raw = fh.read()
        else:
            raw = sys.stdin.read()
        req = json.loads(raw) if raw.strip() else {"op": "ping"}
        resp = handle(req)
        sys.stdout.write(json.dumps(resp))
        return 0
    except Exception as exc:
        sys.stdout.write(
            json.dumps(
                {
                    "ok": False,
                    "error": "worker_exception",
                    "message": str(exc),
                    "trace": traceback.format_exc(),
                }
            )
        )
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
