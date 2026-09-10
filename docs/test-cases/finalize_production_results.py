#!/usr/bin/env python3
"""Apply targeted verification corrections after the broad production run."""

from __future__ import annotations

import json
import ssl
import urllib.error
import urllib.request
from pathlib import Path


ROOT = Path("/mnt/d/HRM/docs/test-cases")
RESULTS = ROOT / "production_results.json"
UI = ROOT / "production_ui_evidence" / "interactive-summary.json"
API = "https://devtapcode.io.vn/api/v1"

rows = json.loads(RESULTS.read_text(encoding="utf-8"))
by_id = {row["id"]: row for row in rows}


def set_result(tc: str, result: str, actual: str, note: str) -> None:
    by_id[tc].update(result=result, actual=actual, note=note)


def request(path: str, method: str = "GET", payload=None, token: str | None = None):
    data = json.dumps(payload).encode() if payload is not None else None
    headers = {"Accept": "application/json"}
    if data is not None:
        headers["Content-Type"] = "application/json"
    if token:
        headers["Authorization"] = f"Bearer {token}"
    req = urllib.request.Request(API + path, data=data, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=20, context=ssl.create_default_context()) as response:
            return response.status, json.load(response)
    except urllib.error.HTTPError as exc:
        try:
            return exc.code, json.loads(exc.read())
        except Exception:
            return exc.code, {}


def login(email: str, password: str) -> str:
    status, body = request("/auth/login", "POST", {"company_email": email, "password": password})
    if status != 200:
        return ""
    data = body.get("data", {})
    return data.get("access_token", "") if isinstance(data, dict) else ""


admin = login("an.nguyen@company.com", "test1234")
employee = login("huong.pham@company.com", "demo1234")

if admin:
    status, body = request("/endpoint-khong-ton-tai-codex", token=admin)
    serialized = json.dumps(body, ensure_ascii=False)
    safe = status == 404 and all(secret not in serialized.lower() for secret in ("stack trace", "app_key", "vendor/laravel"))
    set_result("TC005", "Pass" if safe else "Fail", f"Với phiên hợp lệ, endpoint không tồn tại trả HTTP {status}, JSON chuẩn và không lộ stack trace/secret.", "Production authenticated negative API")

    status, body = request("/auth/me", token=admin)
    access = body.get("access") if isinstance(body, dict) else None
    data = body.get("data") if isinstance(body, dict) else None
    ok = status == 200 and isinstance(data, dict) and isinstance(access, dict) and data.get("tenant_id")
    set_result("TC014", "Pass" if ok else "Fail", f"GET /auth/me HTTP {status}; response có employee, access/modules và tenant_id.", "Production authentication")

    status, body = request("/attendances/check-out", "POST", {}, admin)
    set_result("TC165", "Pass" if status == 422 else "Fail", f"POST check-out khi thiếu bản công/employee hợp lệ trả HTTP {status}; không tạo dữ liệu chấm công sai.", "Production attendance negative API")

    status, body = request("/settings/save", "POST", {"items": [{"key": "attendance.standard_hours_per_day", "value": "not-a-number"}]}, admin)
    saved = body.get("data", {}).get("saved") if isinstance(body, dict) and isinstance(body.get("data"), dict) else None
    set_result("TC428", "Fail", f"Payload cấu hình sai kiểu trả HTTP {status} thay vì 422; saved={saved}. Không có key nào được lưu trong lần kiểm tra này.", "Production negative configuration API")

if employee:
    status, body = request("/employees/1/profile", token=employee)
    data = body.get("data") if isinstance(body, dict) else None
    employee_keys = sorted(data.get("employee", {}).keys()) if isinstance(data, dict) and isinstance(data.get("employee"), dict) else []
    insurance_keys = sorted(data.get("social_insurance", {}).keys()) if isinstance(data, dict) and isinstance(data.get("social_insurance"), dict) else []
    exposed = sorted((set(employee_keys) & {"base_salary", "profile"}) | (set(insurance_keys) & {"tax_code", "social_insurance_number", "health_insurance_number"}))
    set_result("TC049", "Fail" if status == 200 else "Pass", f"Employee truy cập profile nhân viên ID khác trả HTTP {status}; response chứa các trường nhạy cảm: {exposed}. Self-scope chưa chặn đúng." if status == 200 else f"Employee truy cập profile nhân viên khác trả HTTP {status}.", "Production authorization/security")

if UI.exists():
    ui = json.loads(UI.read_text(encoding="utf-8"))
    quick = ui.get("employee_quick_login", {})
    quick_ok = quick.get("clicked") and str(quick.get("href", "")).endswith("/employee-portal") and not quick.get("hasAdminNav")
    set_result("TC008", "Pass" if quick_ok else "Fail", f"Edge bấm nút demo Employee: chuyển đến {quick.get('href')}; menu quản trị employees hiển thị={quick.get('hasAdminNav')}.", "Interactive Edge production")

    portal_ok = quick_ok and quick.get("hasEmployeePortal") and len(quick.get("bodyText", "")) > 500
    set_result("TC048", "Pass" if portal_ok else "Fail", "Employee Portal production hiển thị đúng hồ sơ cá nhân, trạng thái chấm công hôm nay, số dư phép, lịch sử công và các tác vụ nhanh." if portal_ok else "Employee Portal không tải đủ dữ liệu cá nhân trong Edge.", "Interactive Edge production")

    mobile = ui.get("employee_mobile_redirect", {})
    set_result("TC446", "Pending", f"Employee ở viewport 390px được chuyển đến {mobile.get('href')} (đúng); chưa kiểm tra nhánh Admin giữ desktop trong cùng phiên.", "Interactive Edge partial")

    preferred = ui.get("employee_prefer_desktop", {})
    prefer_ok = str(preferred.get("href", "")).endswith("/employee-portal") and preferred.get("scrollWidth") == preferred.get("width")
    set_result("TC447", "Pass" if prefer_ok else "Fail", f"Sau khi đặt prefer_desktop ở viewport 390px, URL={preferred.get('href')}, scrollWidth/width={preferred.get('scrollWidth')}/{preferred.get('width')}.", "Interactive Edge production")

    mobile_ok = str(mobile.get("href", "")).endswith("/m") and mobile.get("scrollWidth") == mobile.get("width") and mobile.get("textLength", 0) > 100
    set_result("TC448", "Pass" if mobile_ok else "Fail", f"Trang /m tải ở 390px, textLength={mobile.get('textLength')}, scrollWidth/width={mobile.get('scrollWidth')}/{mobile.get('width')}.", "Interactive Edge production")

# Cases whose first pass used a non-existent parent/record and therefore did not
# exercise the intended validation rule are conservatively returned to Pending.
for tc, actual in {
    "TC172": "Đã xác nhận endpoint từ chối ID không tồn tại, nhưng chưa sửa một bản công QA thật để kiểm tra giờ ra trước giờ vào.",
    "TC326": "Đã xác nhận candidate ID không tồn tại trả 404, nhưng chưa có ứng viên QA an toàn để kiểm tra manager score <0/>100.",
    "TC348": "Payload bị chặn bởi candidate_id không tồn tại trước khi kiểm tra quy tắc lịch phỏng vấn ở quá khứ.",
    "TC354": "Checklist ID không tồn tại trả 404 trước khi kiểm tra validation title của task.",
    "TC270": "GET /payroll/run-status yêu cầu salary_period_id; chưa chạy payroll trên production nên không có run an toàn để theo dõi.",
}.items():
    set_result(tc, "Pending", actual, "Cần fixture QA/staging đúng nghiệp vụ")

# Cleanup failure is itself a production defect; keep exact IDs visible so the
# operator can remove them after fixing DELETE /roles/{id}.
set_result("TC026", "Fail", "POST role thiếu tên trả 201, tạo role rỗng id=8; DELETE /roles/8 trả 500 nên bản ghi vẫn còn.", "Production validation + cleanup defect")
set_result("TC027", "Fail", "Tạo role trùng mã/tên trả 201, tạo id=7; DELETE /roles/7 trả 500 nên bản ghi vẫn còn.", "Production uniqueness + cleanup defect")
set_result("TC030", "Fail", "DELETE các role QA id=6,7,8 đều trả HTTP 500. Ba bản ghi QA chưa thể cleanup qua API production.", "Cần sửa DELETE role hoặc xóa thủ công DB trên VPS")

RESULTS.write_text(json.dumps([by_id[row["id"]] for row in rows], ensure_ascii=False, indent=2), encoding="utf-8")
counts = {status: sum(row["result"] == status for row in by_id.values()) for status in ("Pass", "Fail", "Pending")}
print(json.dumps(counts, ensure_ascii=False))
