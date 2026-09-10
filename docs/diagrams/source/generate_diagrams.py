from __future__ import annotations

from dataclasses import dataclass
from html import escape
from pathlib import Path
from typing import Iterable


ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / "output"
OUTPUT.mkdir(parents=True, exist_ok=True)


BG = "#F5F1E8"
INK = "#18323F"
MUTED = "#60717A"
NAVY = "#173F5F"
TEAL = "#0F766E"
ORANGE = "#D97706"
RED = "#B94A48"
BLUE = "#2563A6"
PURPLE = "#6B5B95"
LINE = "#8A9AA3"
WHITE = "#FFFFFF"


class Svg:
    def __init__(self, width: int, height: int, title: str):
        self.width = width
        self.height = height
        self.parts = [
            f'<svg xmlns="http://www.w3.org/2000/svg" width="{width}" height="{height}" viewBox="0 0 {width} {height}">',
            "<defs>",
            '<filter id="shadow" x="-20%" y="-20%" width="140%" height="140%"><feDropShadow dx="0" dy="5" stdDeviation="7" flood-color="#18323F" flood-opacity="0.14"/></filter>',
            '<marker id="arrow" markerWidth="10" markerHeight="10" refX="9" refY="3" orient="auto" markerUnits="strokeWidth"><path d="M0,0 L0,6 L9,3 z" fill="#6F8089"/></marker>',
            '<marker id="arrow-teal" markerWidth="10" markerHeight="10" refX="9" refY="3" orient="auto" markerUnits="strokeWidth"><path d="M0,0 L0,6 L9,3 z" fill="#0F766E"/></marker>',
            "</defs>",
            f'<rect width="{width}" height="{height}" fill="{BG}"/>',
            f"<title>{escape(title)}</title>",
        ]

    def add(self, value: str) -> None:
        self.parts.append(value)

    def rect(
        self,
        x: float,
        y: float,
        w: float,
        h: float,
        *,
        fill: str = WHITE,
        stroke: str = "none",
        stroke_width: float = 1,
        rx: float = 16,
        shadow: bool = False,
        opacity: float = 1,
    ) -> None:
        flt = ' filter="url(#shadow)"' if shadow else ""
        self.add(
            f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="{rx}" fill="{fill}" '
            f'stroke="{stroke}" stroke-width="{stroke_width}" opacity="{opacity}"{flt}/>'
        )

    def line(
        self,
        x1: float,
        y1: float,
        x2: float,
        y2: float,
        *,
        stroke: str = LINE,
        width: float = 2,
        dash: str | None = None,
        marker: str | None = None,
        opacity: float = 1,
    ) -> None:
        dash_attr = f' stroke-dasharray="{dash}"' if dash else ""
        marker_attr = f' marker-end="url(#{marker})"' if marker else ""
        self.add(
            f'<line x1="{x1}" y1="{y1}" x2="{x2}" y2="{y2}" stroke="{stroke}" '
            f'stroke-width="{width}" opacity="{opacity}"{dash_attr}{marker_attr}/>'
        )

    def path(
        self,
        d: str,
        *,
        stroke: str = LINE,
        width: float = 2,
        fill: str = "none",
        dash: str | None = None,
        marker: str | None = None,
        opacity: float = 1,
    ) -> None:
        dash_attr = f' stroke-dasharray="{dash}"' if dash else ""
        marker_attr = f' marker-end="url(#{marker})"' if marker else ""
        self.add(
            f'<path d="{d}" fill="{fill}" stroke="{stroke}" stroke-width="{width}" '
            f'opacity="{opacity}"{dash_attr}{marker_attr}/>'
        )

    def ellipse(
        self,
        cx: float,
        cy: float,
        rx: float,
        ry: float,
        *,
        fill: str = WHITE,
        stroke: str = NAVY,
        width: float = 2,
        shadow: bool = False,
    ) -> None:
        flt = ' filter="url(#shadow)"' if shadow else ""
        self.add(
            f'<ellipse cx="{cx}" cy="{cy}" rx="{rx}" ry="{ry}" fill="{fill}" '
            f'stroke="{stroke}" stroke-width="{width}"{flt}/>'
        )

    def text(
        self,
        x: float,
        y: float,
        value: str | Iterable[str],
        *,
        size: int = 24,
        fill: str = INK,
        weight: int | str = 400,
        anchor: str = "start",
        family: str = "Segoe UI, Arial, sans-serif",
        line_height: float = 1.25,
        italic: bool = False,
    ) -> None:
        lines = [value] if isinstance(value, str) else list(value)
        style = ' font-style="italic"' if italic else ""
        self.add(
            f'<text x="{x}" y="{y}" font-family="{family}" font-size="{size}" '
            f'font-weight="{weight}" fill="{fill}" text-anchor="{anchor}"{style}>'
        )
        for index, line in enumerate(lines):
            dy = 0 if index == 0 else size * line_height
            self.add(f'<tspan x="{x}" dy="{dy}">{escape(str(line))}</tspan>')
        self.add("</text>")

    def save(self, name: str) -> Path:
        self.parts.append("</svg>")
        path = OUTPUT / name
        path.write_text("\n".join(self.parts), encoding="utf-8")
        return path


def title(svg: Svg, heading: str, subtitle: str) -> None:
    svg.text(60, 58, heading, size=34, weight=750, fill=NAVY)
    svg.text(60, 92, subtitle, size=17, fill=MUTED)
    svg.line(60, 112, svg.width - 60, 112, stroke="#D3CBBE", width=2)


def actor(svg: Svg, x: float, y: float, label: str, side: str = "left") -> tuple[float, float]:
    svg.add(f'<circle cx="{x}" cy="{y}" r="18" fill="{WHITE}" stroke="{INK}" stroke-width="3"/>')
    svg.line(x, y + 18, x, y + 74, stroke=INK, width=3)
    svg.line(x - 30, y + 42, x + 30, y + 42, stroke=INK, width=3)
    svg.line(x, y + 74, x - 27, y + 112, stroke=INK, width=3)
    svg.line(x, y + 74, x + 27, y + 112, stroke=INK, width=3)
    anchor = "end" if side == "left" else "start"
    tx = x - 42 if side == "left" else x + 42
    svg.text(tx, y + 58, label.split("\n"), size=20, weight=650, anchor=anchor)
    return x, y + 50


def use_case(
    svg: Svg,
    x: float,
    y: float,
    w: float,
    text_value: str,
    color: str,
) -> tuple[float, float]:
    svg.ellipse(x + w / 2, y + 31, w / 2, 31, fill=WHITE, stroke=color, width=2.4, shadow=True)
    lines = text_value.split("\n")
    first_y = y + 27 if len(lines) == 1 else y + 20
    svg.text(x + w / 2, first_y, lines, size=17, weight=650, anchor="middle", line_height=1.1)
    return x + w / 2, y + 31


def connect_actor(svg: Svg, actor_point: tuple[float, float], case_point: tuple[float, float], color: str = LINE) -> None:
    x1, y1 = actor_point
    x2, y2 = case_point
    bend = (x1 + x2) / 2
    svg.path(f"M {x1} {y1} C {bend} {y1}, {bend} {y2}, {x2} {y2}", stroke=color, width=1.7, opacity=0.43)


def generate_use_case() -> None:
    svg = Svg(3000, 1810, "Sơ đồ Use Case theo tác nhân - Hệ thống quản lý nhân sự HRM")
    title(
        svg,
        "SƠ ĐỒ USE CASE THEO TÁC NHÂN",
        "Mỗi tác nhân có một làn kết nối riêng; mũi tên dừng tại viền nghiệp vụ và không đi xuyên qua ellipse.",
    )

    svg.rect(60, 135, 2880, 1580, fill="#FBFAF7", stroke=NAVY, stroke_width=2.4, rx=24)
    svg.rect(970, 137, 1060, 52, fill=NAVY, rx=18)
    svg.text(1500, 171, "HỆ THỐNG QUẢN LÝ NHÂN SỰ", size=23, weight=750, fill=WHITE, anchor="middle")

    # The same use case is intentionally repeated in each relevant actor lane. This
    # keeps every association in a dedicated gutter and makes the diagram legible in Word.
    roles = [
        (
            "ỨNG VIÊN",
            PURPLE,
            [
                ("Xem tin tuyển dụng & JD", PURPLE),
                ("Nộp CV ứng tuyển", PURPLE),
            ],
        ),
        (
            "NHÂN VIÊN",
            TEAL,
            [
                ("Quản lý hồ sơ nhân viên", TEAL),
                ("Check-in / Check-out", BLUE),
                ("Đăng ký nghỉ phép", BLUE),
                ("Đăng ký tăng ca / đổi ca", BLUE),
                ("Xem phiếu lương", ORANGE),
                ("Portal & hồ sơ cá nhân", TEAL),
                ("Yêu cầu, thông báo & chính sách", NAVY),
            ],
        ),
        (
            "QUẢN LÝ BỘ PHẬN",
            BLUE,
            [
                ("Duyệt nghỉ phép", BLUE),
                ("Duyệt tăng ca / đổi ca", BLUE),
                ("Điều chỉnh & xác minh bảng công", BLUE),
                ("Xem bảng công tháng", BLUE),
                ("Yêu cầu, thông báo & chính sách", NAVY),
            ],
        ),
        (
            "HR / ADMIN",
            NAVY,
            [
                ("Quản lý hồ sơ nhân viên", TEAL),
                ("Phòng ban, chức danh & sơ đồ tổ chức", TEAL),
                ("Hợp đồng & ký OTP", TEAL),
                ("Onboarding / Offboarding", TEAL),
                ("Tài sản & quyết định nhân sự", TEAL),
                ("Quản lý máy chấm công", BLUE),
                ("Quản lý nghỉ phép", BLUE),
                ("Điều chỉnh & xác minh bảng công", BLUE),
                ("Quản lý ứng viên Kanban", PURPLE),
                ("Phỏng vấn & tuyển dụng", PURPLE),
                ("Vai trò, phân quyền & cấu hình", NAVY),
            ],
        ),
        (
            "KẾ TOÁN TIỀN LƯƠNG",
            ORANGE,
            [
                ("Tạo / đóng kỳ lương", ORANGE),
                ("Tổng hợp công & chạy lương", ORANGE),
                ("Công khoán & thưởng", ORANGE),
                ("Xem phiếu lương", ORANGE),
                ("Báo cáo nhân sự & lương", ORANGE),
            ],
        ),
        (
            "SUPER ADMIN",
            PURPLE,
            [
                ("Vai trò, phân quyền & cấu hình", NAVY),
                ("Tenant, pháp nhân & audit log", PURPLE),
                ("Đăng nhập, đổi / quên mật khẩu", RED),
            ],
        ),
        (
            "MÁY CHẤM CÔNG",
            BLUE,
            [("Đồng bộ dữ liệu chấm công", BLUE)],
        ),
        (
            "RESUME AI",
            PURPLE,
            [("Đọc, phân tích & chấm điểm CV", PURPLE)],
        ),
    ]

    panel_positions = [
        (105, 215, 675, 830),
        (810, 215, 675, 830),
        (1515, 215, 675, 830),
        (2220, 215, 675, 830),
        (105, 1080, 675, 570),
        (810, 1080, 675, 570),
        (1515, 1080, 675, 570),
        (2220, 1080, 675, 570),
    ]

    for (role_name, role_color, role_cases), (panel_x, panel_y, panel_w, panel_h) in zip(roles, panel_positions):
        svg.rect(
            panel_x,
            panel_y,
            panel_w,
            panel_h,
            fill="#FFFFFF",
            stroke="#D6D0C4",
            stroke_width=1.6,
            rx=20,
            shadow=True,
        )
        svg.rect(panel_x, panel_y, panel_w, 66, fill=role_color, rx=20)
        svg.rect(panel_x, panel_y + 45, panel_w, 21, fill=role_color, rx=0)
        svg.text(panel_x + panel_w / 2, panel_y + 43, role_name, size=20, weight=750, fill=WHITE, anchor="middle")

        # Compact actor symbol on the left; its arm feeds one vertical association rail.
        actor_x = panel_x + 91
        actor_y = panel_y + 143
        svg.add(f'<circle cx="{actor_x}" cy="{actor_y}" r="14" fill="{WHITE}" stroke="{role_color}" stroke-width="3"/>')
        svg.line(actor_x, actor_y + 14, actor_x, actor_y + 58, stroke=role_color, width=3)
        svg.line(actor_x - 23, actor_y + 33, actor_x + 31, actor_y + 33, stroke=role_color, width=3)
        svg.line(actor_x, actor_y + 58, actor_x - 22, actor_y + 89, stroke=role_color, width=3)
        svg.line(actor_x, actor_y + 58, actor_x + 22, actor_y + 89, stroke=role_color, width=3)

        rail_x = panel_x + 196
        case_x = panel_x + 245
        case_w = 395
        first_case_y = panel_y + 122
        available = panel_h - 170
        spacing = min(64, available / max(1, len(role_cases) - 1)) if len(role_cases) > 1 else 0
        case_centers = [first_case_y + index * spacing for index in range(len(role_cases))]
        actor_connection_y = actor_y + 33

        svg.line(actor_x + 31, actor_connection_y, rail_x, actor_connection_y, stroke=role_color, width=2.2)
        if case_centers:
            rail_start = min(actor_connection_y, case_centers[0])
            rail_end = max(actor_connection_y, case_centers[-1])
            svg.line(rail_x, rail_start, rail_x, rail_end, stroke=role_color, width=2.2)

        for (case_label, case_color), center_y in zip(role_cases, case_centers):
            svg.line(
                rail_x,
                center_y,
                case_x,
                center_y,
                stroke=role_color,
                width=2.2,
                marker="arrow",
            )
            svg.ellipse(
                case_x + case_w / 2,
                center_y,
                case_w / 2,
                24,
                fill="#FFFEFC",
                stroke=case_color,
                width=2.3,
                shadow=False,
            )
            svg.text(
                case_x + case_w / 2,
                center_y + 5,
                case_label,
                size=15,
                weight=650,
                anchor="middle",
            )

    legend_items = [
        (TEAL, "Core HR"),
        (BLUE, "Thời gian & chấm công"),
        (ORANGE, "Tiền lương"),
        (PURPLE, "Tuyển dụng / AI"),
        (NAVY, "Dùng chung & quản trị"),
        (RED, "Bảo mật"),
    ]
    legend_x = 350
    for color, label in legend_items:
        svg.add(f'<circle cx="{legend_x}" cy="1684" r="7" fill="{color}"/>')
        svg.text(legend_x + 16, 1690, label, size=14, fill=MUTED)
        legend_x += 390

    svg.text(
        1500,
        1770,
        "Nghiệp vụ dùng chung được lặp lại tại từng tác nhân để các đường kết nối luôn tách biệt và không che nội dung.",
        size=16,
        fill=MUTED,
        anchor="middle",
        italic=True,
    )
    svg.save("01-use-case.svg")


def module_card(
    svg: Svg,
    x: int,
    y: int,
    w: int,
    h: int,
    heading: str,
    color: str,
    tables: list[str],
    note: str,
) -> None:
    svg.rect(x, y, w, h, fill=WHITE, stroke="#D6D0C4", stroke_width=1.5, rx=18, shadow=True)
    svg.rect(x, y, w, 52, fill=color, rx=18)
    svg.rect(x, y + 34, w, 18, fill=color, rx=0)
    svg.text(x + 24, y + 34, heading, size=20, weight=750, fill=WHITE)
    svg.text(x + 24, y + 82, note, size=14, fill=MUTED)
    tag_x = x + 24
    tag_y = y + 108
    max_x = x + w - 24
    for item in tables:
        tag_w = max(112, len(item) * 9 + 30)
        if tag_x + tag_w > max_x:
            tag_x = x + 24
            tag_y += 46
        svg.rect(tag_x, tag_y, tag_w, 32, fill="#F1F5F4", stroke=color, stroke_width=1.2, rx=9)
        svg.text(tag_x + tag_w / 2, tag_y + 22, item, size=14, weight=650, fill=INK, anchor="middle")
        tag_x += tag_w + 12


def generate_main_tables() -> None:
    svg = Svg(1900, 1320, "Các bảng chính cho sơ đồ ERD")
    title(svg, "CÁC BẢNG CHÍNH CHO SƠ ĐỒ ERD", "Nhóm theo module nghiệp vụ; loại bỏ các bảng framework như cache, jobs, sessions và bảng partition con")
    cards = [
        (60, 150, "NỀN TẢNG & PHÂN QUYỀN", NAVY, ["tenants", "legal_entities", "employees", "roles", "permissions", "role_permissions", "employee_roles", "audit_logs"], "Định danh tổ chức, pháp nhân và quyền truy cập"),
        (970, 150, "CƠ CẤU & HỒ SƠ NHÂN SỰ", TEAL, ["departments", "job_families", "positions", "employee_departments", "employment_histories", "qualifications", "certificates", "identity_documents"], "Thông tin nhân viên và quá trình công tác"),
        (60, 430, "HỢP ĐỒNG & ONBOARDING", PURPLE, ["contract_types", "contracts", "contract_templates", "contract_change_logs", "onboarding_checklists", "onboarding_tasks", "profile_change_requests"], "Vòng đời nhân sự, ký hợp đồng và hội nhập"),
        (970, 430, "THỜI GIAN & CHẤM CÔNG", BLUE, ["shift_types", "attendances", "attendance_logs", "attendance_devices", "overtime_requests", "shift_assignments", "shift_swaps", "shift_coverage_requests", "shift_coverage_offers"], "Ca làm, dữ liệu máy chấm công và điều phối ca"),
        (60, 710, "NGHỈ PHÉP & PHÊ DUYỆT", RED, ["leave_types", "leave_balances", "leave_requests", "leave_transactions", "requests", "request_types", "approval_flows", "approval_steps", "approval_histories"], "Đơn từ, số dư phép và luồng phê duyệt"),
        (970, 710, "LƯƠNG & PHÚC LỢI", ORANGE, ["salary_periods", "salary_details", "salary_breakdowns", "salary_components", "piece_rate_entries", "employee_allowances", "employee_deductions", "payroll_adjustments"], "Kỳ lương, chi tiết lương và các khoản cấu thành"),
        (60, 990, "TUYỂN DỤNG & AI", "#7C3E74", ["recruitment_positions", "recruitment_posts", "recruitment_candidates", "recruitment_candidate_cvs", "recruitment_ai_scoring_jobs", "interview_schedules", "manager_reviews"], "Tin tuyển dụng, CV, chấm điểm AI và phỏng vấn"),
        (970, 990, "TÀI SẢN & NỘI BỘ", "#5A6B3C", ["assets", "asset_categories", "asset_locations", "asset_assignments", "news", "policies", "notifications", "service_tickets", "report_templates"], "Tài sản, truyền thông và dịch vụ nội bộ"),
    ]
    for x, y, heading, color, tables, note in cards:
        module_card(svg, x, y, 870, 240, heading, color, tables, note)
    svg.save("02-main-tables.svg")


@dataclass(frozen=True)
class EntityNode:
    name: str
    x: int
    y: int
    color: str
    w: int = 210
    h: int = 48

    @property
    def center(self) -> tuple[float, float]:
        return self.x + self.w / 2, self.y + self.h / 2


def entity(svg: Svg, node: EntityNode) -> None:
    svg.rect(node.x, node.y, node.w, node.h, fill=WHITE, stroke=node.color, stroke_width=2, rx=10, shadow=True)
    svg.rect(node.x, node.y, 10, node.h, fill=node.color, rx=10)
    svg.text(node.x + 24, node.y + 31, node.name, size=15, weight=700)


def relation(svg: Svg, a: EntityNode, b: EntityNode, label: str = "1:N", dashed: bool = False, color: str = LINE) -> None:
    ax, ay = a.center
    bx, by = b.center
    if abs(ax - bx) > abs(ay - by):
        x1 = a.x + a.w if bx > ax else a.x
        y1 = ay
        x2 = b.x if bx > ax else b.x + b.w
        y2 = by
    else:
        x1 = ax
        y1 = a.y + a.h if by > ay else a.y
        x2 = bx
        y2 = b.y if by > ay else b.y + b.h
    mid_x = (x1 + x2) / 2
    svg.path(
        f"M {x1} {y1} C {mid_x} {y1}, {mid_x} {y2}, {x2} {y2}",
        stroke=color,
        width=1.7,
        dash="7 6" if dashed else None,
        marker="arrow" if dashed else None,
        opacity=0.72,
    )
    svg.rect(mid_x - 22, (y1 + y2) / 2 - 12, 44, 23, fill=BG, rx=6)
    svg.text(mid_x, (y1 + y2) / 2 + 5, label, size=12, fill=MUTED, weight=650, anchor="middle")


def generate_relation_erd() -> None:
    svg = Svg(2100, 1580, "Sơ đồ quan hệ ERD - HRM System")
    title(svg, "SƠ ĐỒ QUAN HỆ ERD", "Mô hình khái niệm: thể hiện thực thể chính và lực lượng quan hệ giữa các module")

    areas = [
        (60, 140, 1980, 175, "NỀN TẢNG ĐA TENANT", NAVY),
        (60, 345, 640, 570, "CORE HR & PHÂN QUYỀN", TEAL),
        (730, 345, 640, 570, "THỜI GIAN & NGHỈ PHÉP", BLUE),
        (1400, 345, 640, 570, "LƯƠNG", ORANGE),
        (60, 945, 1280, 540, "TUYỂN DỤNG & ONBOARDING", PURPLE),
        (1370, 945, 670, 540, "AUDIT & TÍCH HỢP", RED),
    ]
    for x, y, w, h, label, color in areas:
        svg.rect(x, y, w, h, fill=WHITE, stroke=color, stroke_width=1.5, rx=20, opacity=0.72)
        svg.rect(x + 18, y + 16, 250, 34, fill=color, rx=9)
        svg.text(x + 143, y + 39, label, size=14, weight=750, fill=WHITE, anchor="middle")

    nodes = {
        "tenants": EntityNode("tenants", 520, 205, NAVY),
        "legal_entities": EntityNode("legal_entities", 930, 205, NAVY),
        "employees": EntityNode("employees", 275, 500, TEAL, 230),
        "departments": EntityNode("departments", 95, 400, TEAL),
        "job_families": EntityNode("job_families", 95, 690, TEAL),
        "positions": EntityNode("positions", 420, 690, TEAL),
        "roles": EntityNode("roles", 95, 805, TEAL),
        "employee_roles": EntityNode("employee_roles", 420, 805, TEAL),
        "contracts": EntityNode("contracts", 420, 400, PURPLE),
        "contract_types": EntityNode("contract_types", 95, 540, PURPLE),
        "leave_types": EntityNode("leave_types", 760, 400, BLUE),
        "leave_balances": EntityNode("leave_balances", 1075, 400, BLUE),
        "leave_requests": EntityNode("leave_requests", 1075, 520, BLUE),
        "shift_types": EntityNode("shift_types", 760, 660, BLUE),
        "attendances": EntityNode("attendances", 1075, 660, BLUE),
        "attendance_devices": EntityNode("attendance_devices", 760, 790, BLUE),
        "attendance_logs": EntityNode("attendance_logs", 1075, 790, BLUE),
        "salary_periods": EntityNode("salary_periods", 1435, 400, ORANGE),
        "salary_details": EntityNode("salary_details", 1745, 515, ORANGE),
        "salary_breakdowns": EntityNode("salary_breakdowns", 1745, 655, ORANGE),
        "salary_components": EntityNode("salary_components", 1435, 770, ORANGE),
        "recruitment_positions": EntityNode("recruitment_positions", 95, 1030, PURPLE, 230),
        "recruitment_posts": EntityNode("recruitment_posts", 405, 1030, PURPLE),
        "recruitment_candidates": EntityNode("recruitment_candidates", 405, 1160, PURPLE, 240),
        "candidate_cvs": EntityNode("recruitment_candidate_cvs", 740, 1060, PURPLE, 260),
        "ai_jobs": EntityNode("recruitment_ai_scoring_jobs", 740, 1190, PURPLE, 270),
        "interviews": EntityNode("interview_schedules", 1060, 1060, PURPLE, 230),
        "onboarding": EntityNode("onboarding_checklists", 95, 1340, PURPLE, 240),
        "onboarding_tasks": EntityNode("onboarding_tasks", 405, 1340, PURPLE, 230),
        "audit_logs": EntityNode("audit_logs", 1435, 1060, RED),
        "resume_backend": EntityNode("resume-backend", 1745, 1180, RED),
        "attendance_bridge": EntityNode("attendance bridge", 1435, 1320, RED),
    }

    relations = [
        ("tenants", "legal_entities", "1:N", False),
        ("legal_entities", "employees", "1:N", False),
        ("departments", "employees", "1:N", False),
        ("job_families", "positions", "1:N", False),
        ("positions", "employees", "1:N", False),
        ("employees", "employees", "1:N", True),
        ("roles", "employee_roles", "1:N", False),
        ("employees", "employee_roles", "1:N", False),
        ("contract_types", "contracts", "1:N", False),
        ("employees", "contracts", "1:N", False),
        ("leave_types", "leave_balances", "1:N", False),
        ("employees", "leave_balances", "1:N", False),
        ("leave_types", "leave_requests", "1:N", False),
        ("employees", "leave_requests", "1:N", False),
        ("shift_types", "attendances", "1:N", False),
        ("employees", "attendances", "1:N", False),
        ("attendance_devices", "attendance_logs", "1:N", False),
        ("employees", "attendance_logs", "1:N", False),
        ("salary_periods", "salary_details", "1:N", False),
        ("employees", "salary_details", "1:N", False),
        ("contracts", "salary_details", "1:N", False),
        ("salary_details", "salary_breakdowns", "1:N", False),
        ("salary_components", "salary_breakdowns", "1:N", True),
        ("recruitment_positions", "recruitment_posts", "1:N", False),
        ("recruitment_positions", "recruitment_candidates", "1:N", False),
        ("recruitment_candidates", "candidate_cvs", "1:1", False),
        ("recruitment_candidates", "ai_jobs", "1:N", False),
        ("recruitment_candidates", "interviews", "1:N", False),
        ("recruitment_candidates", "employees", "hire", True),
        ("employees", "onboarding", "1:N", False),
        ("onboarding", "onboarding_tasks", "1:N", False),
        ("employees", "audit_logs", "1:N", True),
        ("resume_backend", "ai_jobs", "process", True),
        ("attendance_bridge", "attendance_logs", "write", True),
    ]
    for a, b, label_value, dashed in relations:
        if a == b == "employees":
            n = nodes[a]
            svg.path(f"M {n.x + n.w} {n.y + 12} C {n.x + n.w + 95} {n.y - 55}, {n.x + n.w + 95} {n.y + 100}, {n.x + n.w} {n.y + 38}", stroke=LINE, width=1.7, dash="7 6", marker="arrow", opacity=0.72)
            svg.text(n.x + n.w + 80, n.y + 4, "manager", size=12, fill=MUTED, weight=650)
        else:
            relation(svg, nodes[a], nodes[b], label_value, dashed)

    for node in nodes.values():
        entity(svg, node)

    svg.text(1050, 1535, "Đường liền: quan hệ dữ liệu chính. Đường nét đứt: liên kết nghiệp vụ hoặc tích hợp dịch vụ.", size=15, fill=MUTED, anchor="middle")
    svg.save("03-erd-relations.svg")


@dataclass
class TableSpec:
    name: str
    color: str
    fields: list[tuple[str, str, str]]


TABLE_GROUPS: list[tuple[str, str, list[TableSpec]]] = [
    (
        "NỀN TẢNG & TỔ CHỨC",
        NAVY,
        [
            TableSpec("tenants", NAVY, [("id", "bigint", "PK"), ("code", "varchar", "UQ"), ("name", "varchar", "NN"), ("status", "varchar", "NN")]),
            TableSpec("legal_entities", NAVY, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("code", "varchar", ""), ("name", "varchar", "NN"), ("tax_code", "varchar", "")]),
            TableSpec("departments", TEAL, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("legal_entity_id", "bigint", "FK"), ("department_code", "varchar", ""), ("department_name", "varchar", ""), ("status", "boolean", "NN")]),
            TableSpec("job_families", TEAL, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("code", "varchar", "UQ"), ("name", "varchar", "NN")]),
            TableSpec("positions", TEAL, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("job_family_id", "bigint", "FK"), ("position_code", "varchar", ""), ("position_name", "varchar", ""), ("position_level", "varchar", "")]),
            TableSpec("employees", TEAL, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("legal_entity_id", "bigint", "FK"), ("employee_code", "varchar", "UQ"), ("full_name", "varchar", "NN"), ("department_id", "bigint", "FK"), ("position_id", "bigint", "FK"), ("manager_id", "bigint", "FK"), ("company_email", "varchar", "UQ"), ("status", "varchar", "NN"), ("base_salary", "numeric", "")]),
        ],
    ),
    (
        "PHÂN QUYỀN & HỢP ĐỒNG",
        PURPLE,
        [
            TableSpec("roles", PURPLE, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("role_code", "varchar", ""), ("role_name", "varchar", ""), ("is_system_role", "boolean", "NN")]),
            TableSpec("permissions", PURPLE, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("permission_code", "varchar", ""), ("permission_name", "varchar", ""), ("module", "varchar", "")]),
            TableSpec("role_permissions", PURPLE, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("role_id", "bigint", "FK"), ("permission_id", "bigint", "FK")]),
            TableSpec("employee_roles", PURPLE, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("employee_id", "bigint", "FK"), ("role_id", "bigint", "FK"), ("department_id", "bigint", "FK"), ("is_active", "boolean", "NN")]),
            TableSpec("contract_types", PURPLE, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("contract_type_code", "varchar", ""), ("contract_type_name", "varchar", ""), ("status", "varchar", "")]),
            TableSpec("contracts", PURPLE, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("legal_entity_id", "bigint", "FK"), ("employee_id", "bigint", "FK"), ("contract_type_id", "bigint", "FK"), ("position_id", "bigint", "FK"), ("department_id", "bigint", "FK"), ("contract_number", "varchar", ""), ("status", "varchar", ""), ("start_date", "date", ""), ("end_date", "date", "")]),
            TableSpec("onboarding_checklists", PURPLE, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("employee_id", "bigint", "FK"), ("type", "varchar", "NN"), ("status", "varchar", "NN"), ("start_date", "date", "")]),
            TableSpec("onboarding_tasks", PURPLE, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("checklist_id", "bigint", "FK"), ("title", "varchar", "NN"), ("is_done", "boolean", "NN"), ("due_date", "date", "")]),
        ],
    ),
    (
        "THỜI GIAN & NGHỈ PHÉP",
        BLUE,
        [
            TableSpec("leave_types", RED, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("leave_type_code", "varchar", ""), ("leave_type_name", "varchar", ""), ("category", "varchar", ""), ("status", "varchar", "")]),
            TableSpec("leave_balances", RED, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("employee_id", "bigint", "FK"), ("leave_type_id", "bigint", "FK"), ("year", "varchar", ""), ("total_days", "numeric", ""), ("used_days", "numeric", ""), ("remaining_days", "numeric", "")]),
            TableSpec("leave_requests", RED, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("request_id", "bigint", "FK"), ("employee_id", "bigint", "FK"), ("leave_type_id", "bigint", "FK"), ("start_date", "date", ""), ("end_date", "date", ""), ("total_days", "numeric", ""), ("status", "varchar", "")]),
            TableSpec("shift_types", BLUE, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("shift_code", "varchar", ""), ("shift_name", "varchar", ""), ("start_time", "time", ""), ("end_time", "time", ""), ("status", "varchar", "")]),
            TableSpec("attendance_devices", BLUE, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("legal_entity_id", "bigint", "FK"), ("name", "varchar", "NN"), ("brand", "varchar", "NN"), ("protocol", "varchar", "NN"), ("device_token", "varchar", "UQ"), ("status", "varchar", "NN")]),
            TableSpec("attendance_logs", BLUE, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("legal_entity_id", "bigint", "FK"), ("employee_id", "bigint", "FK"), ("employee_code", "varchar", "NN"), ("action", "varchar", "NN"), ("source", "varchar", "NN"), ("device_id", "varchar", ""), ("checked_at", "timestamptz", "NN"), ("status", "varchar", "NN")]),
            TableSpec("attendances", BLUE, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("legal_entity_id", "bigint", "FK"), ("employee_id", "bigint", "FK"), ("shift_type_id", "bigint", "FK"), ("work_date", "date", ""), ("check_in_time", "time", ""), ("check_out_time", "time", ""), ("status", "varchar", "")]),
            TableSpec("overtime_requests", BLUE, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("request_id", "bigint", "FK"), ("employee_id", "bigint", "FK"), ("work_date", "date", ""), ("start_time", "time", ""), ("end_time", "time", ""), ("total_hours", "numeric", ""), ("status", "varchar", "")]),
        ],
    ),
    (
        "LƯƠNG & KIỂM TOÁN",
        ORANGE,
        [
            TableSpec("salary_components", ORANGE, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("code", "varchar", "UQ"), ("name", "varchar", "NN"), ("type", "varchar", "NN"), ("category", "varchar", "NN"), ("is_taxable", "boolean", "NN")]),
            TableSpec("salary_periods", ORANGE, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("legal_entity_id", "bigint", "FK"), ("period_code", "varchar", ""), ("period_name", "varchar", ""), ("start_date", "date", ""), ("end_date", "date", ""), ("status", "varchar", "")]),
            TableSpec("salary_details", ORANGE, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("legal_entity_id", "bigint", "FK"), ("period_id", "bigint", "FK"), ("employee_id", "bigint", "FK"), ("contract_id", "bigint", "FK"), ("gross_salary", "numeric", ""), ("net_salary", "numeric", ""), ("transfer_status", "varchar", "")]),
            TableSpec("salary_breakdowns", ORANGE, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("legal_entity_id", "bigint", "FK"), ("salary_detail_id", "bigint", "FK"), ("item_type", "varchar", ""), ("item_code", "varchar", ""), ("item_name", "varchar", ""), ("amount", "numeric", "")]),
            TableSpec("piece_rate_entries", ORANGE, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("legal_entity_id", "bigint", "FK"), ("employee_id", "bigint", "FK"), ("work_date", "date", "NN"), ("product_name", "varchar", "NN"), ("quantity", "numeric", "NN"), ("unit_rate", "numeric", "NN"), ("amount", "numeric", "NN")]),
            TableSpec("audit_logs", RED, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("actor_employee_id", "bigint", "FK"), ("action", "varchar", "NN"), ("table_name", "varchar", "NN"), ("record_id", "bigint", ""), ("changes", "jsonb", ""), ("created_at", "timestamptz", "NN")]),
        ],
    ),
    (
        "TUYỂN DỤNG & AI",
        PURPLE,
        [
            TableSpec("recruitment_positions", PURPLE, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("position_name", "varchar", ""), ("department_id", "bigint", "FK"), ("employment_type", "varchar", ""), ("required_skills_json", "jsonb", ""), ("status", "varchar", "")]),
            TableSpec("recruitment_posts", PURPLE, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("recruitment_position_id", "bigint", "FK"), ("slug", "varchar", "UQ"), ("title", "varchar", "NN"), ("content", "text", ""), ("requirements", "jsonb", ""), ("deadline", "date", ""), ("status", "varchar", "NN")]),
            TableSpec("recruitment_candidates", PURPLE, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("recruitment_position_id", "bigint", "FK"), ("full_name", "varchar", ""), ("email", "varchar", ""), ("application_status", "varchar", ""), ("ai_score", "numeric", ""), ("ai_scoring_status", "varchar", ""), ("ai_matched_skills_json", "jsonb", ""), ("ai_missing_skills_json", "jsonb", "")]),
            TableSpec("recruitment_candidate_cvs", PURPLE, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("candidate_id", "bigint", "FK/UQ"), ("original_filename", "varchar", ""), ("storage_path", "varchar", "NN"), ("mime_type", "varchar", ""), ("file_size", "bigint", "")]),
            TableSpec("recruitment_ai_scoring_jobs", PURPLE, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("candidate_id", "bigint", "FK"), ("status", "varchar", ""), ("attempts", "varchar", ""), ("max_attempts", "varchar", ""), ("available_at", "timestamptz", ""), ("last_error", "text", "")]),
            TableSpec("interview_schedules", PURPLE, [("id", "bigint", "PK"), ("tenant_id", "bigint", "FK"), ("candidate_id", "bigint", "FK"), ("interviewer_id", "bigint", "FK"), ("department_manager_id", "bigint", "FK"), ("interview_date", "date", ""), ("interview_time", "time", ""), ("status", "varchar", ""), ("result", "varchar", "")]),
        ],
    ),
]


def table_height(spec: TableSpec) -> int:
    return 52 + 30 * len(spec.fields) + 14


def table_card(svg: Svg, x: int, y: int, w: int, spec: TableSpec) -> tuple[int, int, int, int]:
    h = table_height(spec)
    svg.rect(x, y, w, h, fill=WHITE, stroke="#BCC6CB", stroke_width=1.3, rx=12, shadow=True)
    svg.rect(x, y, w, 48, fill=spec.color, rx=12)
    svg.rect(x, y + 32, w, 16, fill=spec.color, rx=0)
    svg.text(x + 18, y + 31, spec.name, size=18, weight=750, fill=WHITE)
    for index, (name, field_type, flag) in enumerate(spec.fields):
        row_y = y + 48 + index * 30
        if index % 2 == 1:
            svg.rect(x + 1, row_y, w - 2, 30, fill="#F6F8F8", rx=0)
        flag_color = RED if "PK" in flag else BLUE if "FK" in flag else MUTED
        svg.text(x + 15, row_y + 20, name, size=13, weight=650 if flag else 450)
        svg.text(x + w - 82, row_y + 20, field_type, size=11, fill=MUTED, anchor="end", family="Consolas, monospace")
        if flag:
            svg.text(x + w - 12, row_y + 20, flag, size=10, fill=flag_color, weight=750, anchor="end")
    return x, y, w, h


def detailed_relation(
    svg: Svg,
    boxes: dict[str, tuple[int, int, int, int]],
    source: str,
    target: str,
    *,
    dashed: bool = False,
    label: str = "",
) -> None:
    sx, sy, sw, sh = boxes[source]
    tx, ty, tw, th = boxes[target]
    if sx < tx:
        x1, y1 = sx + sw, sy + sh / 2
        x2, y2 = tx, ty + th / 2
    elif sx > tx:
        x1, y1 = sx, sy + sh / 2
        x2, y2 = tx + tw, ty + th / 2
    else:
        x1, y1 = sx + sw / 2, sy + sh
        x2, y2 = tx + tw / 2, ty
    mid_x = (x1 + x2) / 2
    svg.path(
        f"M {x1} {y1} C {mid_x} {y1}, {mid_x} {y2}, {x2} {y2}",
        stroke="#7E8F98",
        width=1.6,
        dash="8 7" if dashed else None,
        marker="arrow" if dashed else None,
        opacity=0.48,
    )
    if label:
        svg.rect(mid_x - 26, (y1 + y2) / 2 - 12, 52, 22, fill=BG, rx=5)
        svg.text(mid_x, (y1 + y2) / 2 + 4, label, size=10, fill=MUTED, weight=650, anchor="middle")


def generate_detailed_erd() -> None:
    width = 3400
    height = 3160
    svg = Svg(width, height, "Sơ đồ chi tiết thực thể - HRM System")
    title(svg, "SƠ ĐỒ CHI TIẾT THỰC THỂ", "Các bảng nghiệp vụ cốt lõi, trường quan trọng và liên kết PK/FK của database PostgreSQL đang chạy")

    column_x = [55, 720, 1385, 2050, 2715]
    card_w = 600
    boxes: dict[str, tuple[int, int, int, int]] = {}
    for column_index, (group_name, group_color, specs) in enumerate(TABLE_GROUPS):
        x = column_x[column_index]
        svg.rect(x, 140, card_w, 48, fill=group_color, rx=12)
        svg.text(x + card_w / 2, 172, group_name, size=18, weight=750, fill=WHITE, anchor="middle")
        y = 210
        for spec in specs:
            boxes[spec.name] = table_card(svg, x, y, card_w, spec)
            y += table_height(spec) + 24

    relations = [
        ("tenants", "legal_entities", False, "1:N"),
        ("legal_entities", "employees", False, "1:N"),
        ("departments", "employees", False, "1:N"),
        ("job_families", "positions", False, "1:N"),
        ("positions", "employees", False, "1:N"),
        ("roles", "employee_roles", False, "1:N"),
        ("permissions", "role_permissions", False, "1:N"),
        ("roles", "role_permissions", False, "1:N"),
        ("employees", "employee_roles", False, "1:N"),
        ("contract_types", "contracts", False, "1:N"),
        ("employees", "contracts", False, "1:N"),
        ("employees", "onboarding_checklists", False, "1:N"),
        ("onboarding_checklists", "onboarding_tasks", False, "1:N"),
        ("leave_types", "leave_balances", False, "1:N"),
        ("employees", "leave_balances", False, "1:N"),
        ("leave_types", "leave_requests", False, "1:N"),
        ("employees", "leave_requests", False, "1:N"),
        ("attendance_devices", "attendance_logs", False, "1:N"),
        ("employees", "attendance_logs", False, "1:N"),
        ("shift_types", "attendances", False, "1:N"),
        ("employees", "attendances", False, "1:N"),
        ("employees", "overtime_requests", False, "1:N"),
        ("salary_periods", "salary_details", False, "1:N"),
        ("employees", "salary_details", False, "1:N"),
        ("contracts", "salary_details", False, "1:N"),
        ("salary_details", "salary_breakdowns", False, "1:N"),
        ("salary_components", "salary_breakdowns", True, "code"),
        ("employees", "piece_rate_entries", False, "1:N"),
        ("employees", "audit_logs", True, "actor"),
        ("recruitment_positions", "recruitment_posts", False, "1:N"),
        ("recruitment_positions", "recruitment_candidates", False, "1:N"),
        ("recruitment_candidates", "recruitment_candidate_cvs", False, "1:1"),
        ("recruitment_candidates", "recruitment_ai_scoring_jobs", False, "1:N"),
        ("recruitment_candidates", "interview_schedules", False, "1:N"),
        ("employees", "interview_schedules", True, "interviewer"),
    ]
    for source, target, dashed, label_value in relations:
        detailed_relation(svg, boxes, source, target, dashed=dashed, label=label_value)

    # Draw table cards a second time so relationship lines stay behind the content.
    for column_index, (_, _, specs) in enumerate(TABLE_GROUPS):
        x = column_x[column_index]
        for spec in specs:
            _, y, _, _ = boxes[spec.name]
            table_card(svg, x, y, card_w, spec)

    svg.rect(55, 3075, width - 110, 48, fill="#E8E2D6", rx=10)
    svg.text(width / 2, 3106, "PK: khóa chính · FK: khóa ngoại · UQ: duy nhất · NN: không được rỗng · Các cột meta/timestamps được lược bớt để sơ đồ dễ đọc.", size=15, fill=MUTED, anchor="middle")
    svg.save("04-detailed-erd.svg")


def generate_readme() -> None:
    content = """# Bộ sơ đồ HRM System

Tài liệu được dựng theo cấu trúc của file Word mẫu `nhóm 1 (4).docx`, nhưng nội dung lấy từ source và database PostgreSQL hiện tại của dự án `BEHRM`.

1. `01-use-case.svg`: Sơ đồ Use Case.
2. `02-main-tables.svg`: Các bảng chính cho ERD, nhóm theo module.
3. `03-erd-relations.svg`: Sơ đồ quan hệ ERD mức khái niệm.
4. `04-detailed-erd.svg`: Sơ đồ chi tiết thực thể với trường PK/FK quan trọng.

Các bảng framework (`cache`, `sessions`, `jobs`, `failed_jobs`...) và các partition con của `attendance_logs` không được đưa vào sơ đồ nghiệp vụ.
"""
    (OUTPUT / "README.md").write_text(content, encoding="utf-8")


def generate_html_report() -> None:
    html = """<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<title>Bộ 4 sơ đồ HRM System</title>
<style>
  @page { size: A3 landscape; margin: 10mm; }
  * { box-sizing: border-box; }
  body { margin: 0; color: #18323f; font-family: "Segoe UI", Arial, sans-serif; background: #fff; }
  .page { page-break-after: always; min-height: 270mm; display: flex; flex-direction: column; }
  .page:last-child { page-break-after: auto; }
  .cover { align-items: center; justify-content: center; text-align: center; background: #f5f1e8; border: 2px solid #173f5f; padding: 30mm; }
  .cover h1 { font-size: 34pt; margin: 0 0 8mm; color: #173f5f; }
  .cover h2 { font-size: 25pt; margin: 0 0 14mm; color: #0f766e; }
  .cover p { font-size: 13pt; color: #60717a; max-width: 850px; }
  h2 { margin: 0 0 2mm; color: #173f5f; font-size: 22pt; }
  .lead { margin: 0 0 4mm; color: #60717a; font-size: 10.5pt; }
  .diagram { display: block; margin: auto; max-width: 100%; max-height: 238mm; object-fit: contain; }
  .detail { max-height: 232mm; }
  .caption { margin: 2mm 0 0; text-align: center; color: #60717a; font-size: 9.5pt; font-style: italic; }
</style>
</head>
<body>
  <section class="page cover">
    <h1>BỘ SƠ ĐỒ PHÂN TÍCH VÀ THIẾT KẾ</h1>
    <h2>HỆ THỐNG QUẢN LÝ NHÂN SỰ HRM</h2>
    <p>Xây dựng theo cấu trúc file mẫu <strong>nhóm 1 (4).docx</strong>. Nội dung được đối chiếu từ source Laravel/Vue và database PostgreSQL đang chạy của dự án BEHRM.</p>
  </section>
  <section class="page">
    <h2>3.1. Sơ đồ Use Case</h2>
    <p class="lead">Các tác nhân và nhóm nghiệp vụ chính được tổng hợp từ router frontend, API backend và các tích hợp máy chấm công / resume-backend.</p>
    <img class="diagram" src="01-use-case.png" alt="Sơ đồ Use Case">
    <p class="caption">Hình 1. Sơ đồ Use Case của hệ thống HRM</p>
  </section>
  <section class="page">
    <h2>3.2. Các bảng chính cho sơ đồ ERD</h2>
    <p class="lead">Các bảng nghiệp vụ được gom theo module. Bảng framework và các partition con của attendance_logs không đưa vào phạm vi báo cáo.</p>
    <img class="diagram" src="02-main-tables.png" alt="Các bảng chính cho ERD">
    <p class="caption">Hình 2. Các bảng chính cho sơ đồ ERD</p>
  </section>
  <section class="page">
    <h2>3.3. Sơ đồ quan hệ ERD</h2>
    <p class="lead">Sơ đồ mức khái niệm tập trung vào lực lượng 1:N, 1:1 và các liên kết nghiệp vụ giữa Core HR, chấm công, lương, tuyển dụng và tích hợp ngoài.</p>
    <img class="diagram" src="03-erd-relations.png" alt="Sơ đồ quan hệ ERD">
    <p class="caption">Hình 3. Sơ đồ quan hệ ERD của hệ thống HRM</p>
  </section>
  <section class="page">
    <h2>3.4. Sơ đồ chi tiết thực thể</h2>
    <p class="lead">Tên bảng, cột quan trọng, kiểu dữ liệu và ký hiệu PK/FK được lấy từ schema PostgreSQL hiện tại; các cột meta và timestamps được lược bớt để tăng khả năng đọc.</p>
    <img class="diagram detail" src="04-detailed-erd.png" alt="Sơ đồ chi tiết thực thể">
    <p class="caption">Hình 4. Sơ đồ chi tiết thực thể</p>
  </section>
</body>
</html>
"""
    (OUTPUT / "Bo_4_So_Do_HRM_System.html").write_text(html, encoding="utf-8")


def main() -> None:
    generate_use_case()
    generate_main_tables()
    generate_relation_erd()
    generate_detailed_erd()
    generate_readme()
    generate_html_report()
    for path in sorted(OUTPUT.iterdir()):
        print(path)


if __name__ == "__main__":
    main()
