# AUTHORITATIVE GOAL: TỔ CHỨC LẠI SOURCE TREE DỰ ÁN HRM

## USER_OBJECTIVE
Tổ chức và tái cấu trúc toàn bộ cây thư mục dự án HRM tại `D:\HRM` một cách gọn gàng, ngăn nắp, chuẩn hóa kiến trúc phân tách rõ ràng theo các tầng nghiệp vụ:
- Tất cả Frontend (FE) được gom về thư mục `FE/`.
- Tất cả Backend (BE) trả API cho FE được gom về thư mục `BE/`.
- Dịch vụ AI (AutoRecruit) được tách riêng thành thư mục `ai/` ngang hàng ở root.
- Toàn bộ tài liệu dự án (.md, báo cáo, đồ án, diagrams, test cases) được gom về thư mục `docs/`.
- Xóa bỏ các thư mục rác / ngoài phạm vi dự án trong `D:\HRM` (`MinerU`, `VAIC-main`).
- Bảo toàn và thiết lập đúng vị trí cho `.claude/`, `.codegraph/`, `.git/` tại thư mục gốc `D:\HRM`.

---

## SELECTED_PLAN
Phương án được người dùng duyệt: **Tách riêng `ai/` ngang hàng, di chuyển root dự án ra `D:\HRM` và xóa sạch thư mục thừa**.

Cây thư mục đích tại `D:\HRM`:
```
D:\HRM\
├── FE/                      # Toàn bộ Frontend Vue 3 + Vite + Tailwind
│   ├── src/                 # Source code Vue (components, views, router, stores, assets...)
│   ├── public/              # Static assets công khai
│   ├── index.html           # File entry HTML
│   ├── shared/              # Schema & definitions dùng chung
│   ├── scripts/             # Script test / audit UI & endpoints phía client
│   ├── tests/               # Playwright browser tests
│   ├── package.json         # Cấu hình dependencies npm FE
│   ├── vite.config.js       # Config Vite (cập nhật alias và root phù hợp)
│   ├── tailwind.config.js   # Config Tailwind CSS
│   ├── postcss.config.js    # Config PostCSS
│   ├── components.json      # Config shadcn-vue UI
│   └── ...
│
├── BE/                      # Toàn bộ Backend Laravel 11 API phục vụ FE
│   ├── app/                 # Controllers, Models, Services, Middlewares
│   ├── bootstrap/           # Laravel app bootstrap & cache
│   ├── config/              # Laravel configuration files
│   ├── database/            # Migrations, Seeders, Factories
│   ├── routes/              # api.php, web.php, console.php
│   ├── storage/             # Logs, framework cache, uploaded files
│   ├── tests/               # PHPUnit feature & unit tests
│   ├── artisan              # Laravel CLI
│   ├── composer.json        # PHP dependencies
│   ├── composer.lock        # Locked dependencies
│   ├── .env                 # Environment config
│   ├── docker-compose.yml   # Docker services cho BE
│   └── ...
│
├── ai/                      # AutoRecruit AI Resume Screening Service
│   ├── app/                 # FastAPI service & modules (parser, rubric, ranker)
│   ├── data/                # Dữ liệu runtime, CV test, JD
│   ├── mineru-local/        # Dockerfile & pipeline OCR MinerU local
│   ├── skills/              # Quy tắc AI prompts
│   ├── training/            # Pipeline train/rank model embedding
│   ├── Dockerfile           # Build container AI Backend
│   ├── compose.yaml         # Docker compose cho Ollama + AI backend + MinerU
│   └── ...
│
├── docs/                    # Toàn bộ tài liệu, báo cáo, đặc tả của dự án
│   ├── api/                 # Tài liệu API (APi.md, endpoint-manifest.json...)
│   ├── reports/             # Báo cáo tiến độ, đồ án tốt nghiệp, Word, PPTX (baocao, Doan.md)
│   ├── diagrams/            # Sơ đồ kiến trúc, luồng nghiệp vụ (từ HRM_Diagrams)
│   ├── test-cases/          # Bảng test case Excel, script kiểm thử (từ HRM_TestCases)
│   ├── superpowers/         # Kế hoạch và đặc tả superpowers
│   └── architecture/        # Phân tích schema, migration, thiết kế kỹ thuật
│
├── .claude/                 # Cấu hình Claude & workspace goal
├── .codegraph/              # Dữ liệu index CodeGraph
├── .git/                    # Git repository quản lý version control toàn dự án
└── .gitignore               # Root gitignore
```

---

## APPROVED_SCOPE
### Bao gồm (IN SCOPE):
1. **Di chuyển và cấu trúc lại Frontend (`FE/`)**:
   - Chuyển `BEHRM/client/*`, `BEHRM/package.json`, `BEHRM/package-lock.json`, `BEHRM/vite.config.js`, `BEHRM/tailwind.config.js`, `BEHRM/postcss.config.js`, `BEHRM/components.json`, `BEHRM/playwright.config.mjs`, `BEHRM/shared/`, `BEHRM/scripts/`, `BEHRM/tests/` vào `D:\HRM\FE\`.
   - Cập nhật đường dẫn trong `FE/vite.config.js` để khớp với vị trí mới.
2. **Di chuyển và cấu trúc lại Backend (`BE/`)**:
   - Di chuyển toàn bộ Laravel từ `BEHRM/Doan2_v2/Doan2/*` ra `D:\HRM\BE\`.
3. **Di chuyển dịch vụ AI (`ai/`)**:
   - Di chuyển `BEHRM/AutoRecruit-main/*` ra `D:\HRM\ai\`.
4. **Hợp nhất tài liệu (`docs/`)**:
   - Gom các file tài liệu trong `BEHRM/docs/`, `BEHRM/baocao/`, `BEHRM/Doan.md`, `BEHRM/APi.md`, `HRM_Diagrams/`, `HRM_TestCases/` vào `D:\HRM\docs\`.
5. **Dọn dẹp thư mục gốc `D:\HRM`**:
   - Xóa bỏ hoàn toàn thư mục `VAIC-main` (theo phê duyệt của người dùng).
   - Xóa bỏ thư mục `MinerU` (clone dư thừa).
   - Xóa các thư mục rỗng sau khi di chuyển (`BEHRM`, `HRM_Diagrams`, `HRM_TestCases`).
6. **Bảo toàn và định vị hạ tầng repo**:
   - Di chuyển `.git`, `.claude`, `.codegraph` từ `BEHRM/` ra `D:\HRM/`.
   - Cập nhật `.gitignore` tại root.

### Loại trừ (OUT OF SCOPE):
- Không sửa logic nghiệp vụ của mã nguồn FE/BE/AI ngoài việc hiệu chỉnh các đường dẫn cấu hình (vite config, path aliases) nếu cần thiết để đảm bảo hệ thống build/run bình thường.
- Không tự ý commit hoặc push lên remote Git trừ khi có yêu cầu cụ thể.

---

## ACCEPTANCE_CRITERIA
1. Cấu trúc thư mục tại `D:\HRM` chỉ gồm: `FE/`, `BE/`, `ai/`, `docs/`, `.claude/`, `.codegraph/`, `.git/`, `.gitignore`.
2. Không còn tồn tại các thư mục: `BEHRM`, `VAIC-main`, `MinerU`, `HRM_Diagrams`, `HRM_TestCases` ở root.
3. `FE/`:
   - Chạy lệnh `npm run build` hoặc `npm run dev` kiểm tra syntax/config thành công mà không lỗi đường dẫn import.
4. `BE/`:
   - `artisan` và cấu trúc Laravel nằm ngay tại `D:\HRM\BE\`, lệnh `php artisan --version` nhận diện ứng dụng.
5. `ai/`:
   - Toàn bộ dịch vụ AutoRecruit nằm tại `D:\HRM\ai\`, sẵn sàng khởi chạy docker compose.
6. `docs/`:
   - Toàn bộ báo cáo, diagrams, test cases, file `.md` được gom gọn gàng theo phân loại.
7. Git repository (`git status`) hoạt động trơn tru tại `D:\HRM`.

---

## CONSTRAINTS
1. **Safety Constraints**:
   - Trước khi xoá `BEHRM`, phải bảo đảm 100% dữ liệu mã nguồn đã được chuyển sang các thư mục tương ứng an toàn.
   - Thao tác di chuyển dữ liệu phải đảm bảo không làm mất các file ẩn (.env, .gitignore, .codegraph...).
2. **Git Constraints**:
   - Không được làm mất lịch sử git (git log) khi chuyển `.git` ra root.
   - Không commit hay push tự ý.

---

## RELEVANT_FILES
- `BEHRM/client/*` -> `FE/`
- `BEHRM/package.json`, `BEHRM/vite.config.js`, `BEHRM/tailwind.config.js` -> `FE/`
- `BEHRM/Doan2_v2/Doan2/*` -> `BE/`
- `BEHRM/AutoRecruit-main/*` -> `ai/`
- `BEHRM/docs/*`, `BEHRM/baocao/*`, `BEHRM/Doan.md`, `BEHRM/APi.md` -> `docs/`
- `HRM_Diagrams/*` -> `docs/diagrams/`
- `HRM_TestCases/*` -> `docs/test-cases/`
- `BEHRM/.git/` -> `.git/`
- `BEHRM/.codegraph/` -> `.codegraph/`
- `BEHRM/.claude/` -> `.claude/`

---

## IMPLEMENTATION_REQUIREMENTS
1. Tạo các thư mục đích: `FE/`, `BE/`, `ai/`, `docs/`.
2. Di chuyển các thành phần Backend (`BEHRM/Doan2_v2/Doan2/*`) sang `BE/`.
3. Di chuyển các thành phần Frontend (`BEHRM/client/*`, `BEHRM/shared/`, configs, scripts, tests) sang `FE/`.
4. Điều chỉnh `FE/vite.config.js` phù hợp với cấu trúc mới (index.html, src alias, outDir).
5. Di chuyển dịch vụ AI (`BEHRM/AutoRecruit-main/*`) sang `ai/`.
6. Di chuyển toàn bộ tài liệu từ `BEHRM/docs`, `BEHRM/baocao`, `Doan.md`, `APi.md`, `HRM_Diagrams`, `HRM_TestCases` sang `docs/`.
7. Chuyển `.git`, `.codegraph`, `.claude` từ `BEHRM/` ra `D:\HRM/`.
8. Xóa thư mục rác: `VAIC-main`, `MinerU`, và dọn dẹp các thư mục nguồn rỗng (`BEHRM`, `HRM_Diagrams`, `HRM_TestCases`).
9. Cập nhật `.gitignore` tại root.

---

## TEST_REQUIREMENTS
1. Kiểm tra cấu trúc thư mục bằng `ls -la /d/HRM`.
2. Kiểm tra `git status` tại root `D:\HRM`.
3. Kiểm tra Frontend: `npm run build` (hoặc kiểm tra cú pháp vite config).
4. Kiểm tra Backend: `php artisan --version` (hoặc kiểm tra cú pháp index.php/artisan).

---

## VERIFICATION_REQUIREMENTS
1. Xác nhận độc lập danh sách file và thư mục tại `D:\HRM` đã khớp 100% với mục tiêu.
2. Xác nhận không còn thư mục thừa hoặc thất lạc file mã nguồn quan trọng.
3. Báo cáo chi tiết kết quả tái tổ chức cho người dùng.

---

## CURRENT_STATUS
DONE
