Giả định này hợp lý và phù hợp với mô hình “Human-in-the-loop”. Tuy nhiên, điểm HR/Trưởng phòng không tự động đồng nghĩa với khách quan. Hệ thống cần thu thập đánh giá có cấu trúc, kiểm soát thiên kiến và chỉ huấn luyện lại theo từng phiên bản.
Luồng đề xuất
CV + JD
   ↓
AI chấm độc lập theo từng tiêu chí
   ↓
HR/Trưởng phòng chấm độc lập, chưa nhìn điểm AI
   ↓
So sánh kết quả và xử lý chênh lệch
   ↓
Kết quả phỏng vấn/thử việc
   ↓
Tạo bộ dữ liệu đã kiểm duyệt
   ↓
Huấn luyện và đánh giá model mới
   ↓
Chỉ triển khai nếu model mới tốt hơn model hiện tại
1. Chấm theo rubric của từng JD
Không nên chỉ yêu cầu AI trả về một điểm tổng. Mỗi tin tuyển dụng cần có bộ tiêu chí:
Kỹ năng bắt buộc.
Kỹ năng ưu tiên.
Kinh nghiệm phù hợp.
Kiến thức chuyên môn.
Dự án liên quan.
Học vấn/chứng chỉ nếu thật sự cần thiết.
Mức độ ổn định và phù hợp công việc.
Mỗi tiêu chí nên có:
{
  "criterion": "Kinh nghiệm Laravel",
  "weight": 20,
  "score": 4,
  "max_score": 5,
  "evidence": "Ứng viên có 3 năm làm Laravel tại Công ty ABC",
  "confidence": 0.92
}
AI phải trích dẫn bằng chứng từ CV. Không tìm thấy thông tin thì ghi “Không có bằng chứng”, không được tự suy diễn.
2. Phần HR/Trưởng phòng chấm lại
Giao diện nên cho phép chấm từng tiêu chí từ 0–5, kèm nhận xét:
Điểm HR/Trưởng phòng.
Bằng chứng hoặc lý do điều chỉnh.
Quyết định: đạt, cần xem xét hoặc không đạt.
Tiêu chí AI đánh giá sai.
Tiêu chí JD bị thiếu hoặc đặt trọng số chưa hợp lý.
Nên ẩn điểm AI trước khi người đánh giá hoàn thành để tránh họ bị “neo” vào kết quả của AI. Sau khi gửi mới hiển thị phần so sánh.
Không nên ghi đè điểm AI. Hãy lưu riêng:
ai_score: kết quả ban đầu.
human_score: kết quả người duyệt.
final_score: kết quả sau đối chiếu.
hiring_outcome: kết quả phỏng vấn, tuyển dụng hoặc thử việc.
3. Dữ liệu để AI học
Dữ liệu tốt nhất không chỉ là điểm của HR mà còn là kết quả thực tế:
Điểm Trưởng phòng chuyên môn.
Kết quả phỏng vấn.
Ứng viên có nhận việc hay không.
Kết quả thử việc.
Đánh giá hiệu suất sau 2–3 tháng.
Nếu chỉ học từ thao tác “Tuyển/Từ chối”, AI có thể học lại sở thích chủ quan của một người thay vì khả năng làm việc thực tế.
4. Không cho AI học ngay sau mỗi đánh giá
Không nên để model tự cập nhật online sau mỗi lần HR chấm. Một đánh giá sai có thể làm lệch toàn bộ kết quả tiếp theo.
Nên thực hiện theo đợt:
Thu thập tối thiểu một lượng đánh giá đủ tin cậy.
Loại bỏ đánh giá thiếu lý do hoặc bất thường.
Chia dữ liệu thành tập train, validation và test.
Huấn luyện model mới với mã phiên bản.
So sánh với model production.
Chỉ triển khai khi model mới cải thiện rõ ràng.
Giai đoạn đầu chưa cần fine-tune toàn bộ LLM. Có thể dùng điểm con của AI làm đầu vào cho một mô hình hiệu chỉnh hoặc Learning-to-Rank. Cách này nhẹ, dễ kiểm soát và phù hợp với resume-backend chạy local.
5. Kiểm soát thiên kiến
Không nên đưa những dữ liệu sau vào việc chấm điểm nếu JD không yêu cầu hợp pháp:
Giới tính.
Ảnh đại diện.
Tuổi hoặc ngày sinh.
Tình trạng hôn nhân.
Quê quán, địa chỉ.
Tôn giáo.
Các thông tin cá nhân không liên quan năng lực.
Nên ẩn tên và ảnh trong màn hình chấm chuyên môn. Đồng thời kiểm tra xem model có thường xuyên cho điểm thấp bất thường đối với một nhóm ứng viên nào không.
6. Cách tính điểm ban đầu
Trong thời gian chưa có nhiều dữ liệu, có thể sử dụng:
Điểm tham khảo = AI 40% + HR/Trưởng phòng 60%
Nhưng quyết định cuối vẫn do người có thẩm quyền thực hiện. Khi dữ liệu đủ tốt, AI học để dự đoán human_score và hiring_outcome, không học trực tiếp từ một điểm tổng thiếu giải thích.
Cấu trúc dữ liệu nên bổ sung
ai_assessments: điểm AI, rubric, bằng chứng, độ tin cậy và model version.
human_reviews: người chấm, điểm từng tiêu chí và nhận xét.
assessment_disagreements: các tiêu chí AI và người chấm chênh lệch lớn.
recruitment_outcomes: kết quả phỏng vấn, nhận việc và thử việc.
training_datasets: phiên bản dữ liệu huấn luyện.
model_versions: model, prompt, trọng số, chỉ số đánh giá và ngày triển khai.
Kết luận: ý tưởng của bạn khả thi, nhưng nên gọi đây là hệ thống “AI được hiệu chỉnh bằng phản hồi chuyên môn”, không phải để AI tự học trực tiếp từ mọi thao tác của HR. Thiết kế đúng sẽ giúp điểm số nhất quán hơn; thiết kế sai có thể khiến AI chỉ tự động hóa thiên kiến của người chấm.


2:40 PM








những gì bạn đề xuất cho tôi nên viết bằng moduel hay là skill để cho AI resume học






2:42 PM






Nên triển khai chủ yếu dưới dạng module, còn skill chỉ dùng để chuẩn hóa cách AI chấm. AI không thật sự “học” chỉ bằng cách sửa một file skill.
Phân biệt
Thành phần	Vai trò
Module	Xử lý CV, chấm điểm, nhận phản hồi, tạo dataset, huấn luyện và quản lý model
Skill/prompt	Quy định cách AI đọc JD, chấm từng tiêu chí, trích bằng chứng và trả JSON
Database	Lưu điểm AI, điểm HR, nhận xét và kết quả tuyển dụng
Training pipeline	Dùng dữ liệu đã kiểm duyệt để tạo model mới
RAG/few-shot	Cho AI tham khảo các đánh giá đúng trước đây mà chưa cần fine-tune


Kiến trúc phù hợp
resume-backend
├── modules
│   ├── resume_parser
│   ├── jd_rubric
│   ├── candidate_scoring
│   ├── human_feedback
│   ├── training_dataset
│   ├── model_training
│   └── model_evaluation
├── skills
│   ├── score_resume.md
│   ├── build_jd_rubric.md
│   └── compare_human_review.md
├── prompts
│   └── versions
└── models
    └── registry
Các module chính:
resume_parser: bóc tách CV thành JSON.
jd_rubric: chuyển JD thành tiêu chí và trọng số.
candidate_scoring: AI chấm điểm kèm bằng chứng.
human_feedback: nhận điểm và nhận xét của HR/Trưởng phòng.
training_dataset: lọc phản hồi đủ chất lượng thành dữ liệu học.
model_training: huấn luyện bộ hiệu chỉnh hoặc mô hình xếp hạng.
model_evaluation: so sánh model cũ và mới trước khi triển khai.
Skill nên chứa gì?
Skill chỉ nên chứa quy trình ổn định như:
- Chỉ chấm dựa trên CV và JD được cung cấp.
- Không suy diễn thông tin không xuất hiện trong CV.
- Mỗi điểm phải có bằng chứng.
- Không dùng tuổi, giới tính, ảnh hoặc tình trạng hôn nhân.
- Trả về đúng JSON schema.
- Phân biệt tiêu chí bắt buộc và tiêu chí ưu tiên.
Không nên:
Ghi trực tiếp từng phản hồi của HR vào file skill.
Tự sửa skill sau mỗi lần chấm.
Dùng skill làm nơi lưu dữ liệu huấn luyện.
Cho model production tự cập nhật trọng số tức thời.
Cách triển khai theo giai đoạn
Giai đoạn đầu: module chấm điểm + skill chuẩn hóa + lưu phản hồi vào database.
Khi có dữ liệu: dùng RAG/few-shot để lấy các đánh giá đã được xác nhận làm ví dụ.
Khi dữ liệu đủ lớn và sạch: huấn luyện một model hiệu chỉnh điểm hoặc Learning-to-Rank.
Sau mỗi lần huấn luyện: chạy đánh giá offline và chỉ đưa model mới lên production nếu tốt hơn.
Khuyến nghị của mình là chưa fine-tune LLM ngay. Hãy giữ LLM làm nhiệm vụ đọc hiểu CV/JD, sau đó dùng một module hiệu chỉnh để học cách Trưởng phòng đánh trọng số:
CV + JD
   ↓
LLM trả điểm từng tiêu chí
   ↓
Scoring/calibration module
   ↓
Điểm dự đoán đã hiệu chỉnh
Như vậy, module là hệ thống học, còn skill là bộ quy tắc làm việc của AI. Dự án của bạn nên sử dụng cả hai, nhưng dữ liệu phản hồi và quá trình học phải nằm trong module/backend, không nằm trong skill.


2:42 PM








resume_parser: bóc tách CV thành JSON.
ở đoạn này có nên thêm opendatalab/mineru để cho nó làm việc này không






2:44 PM






Worked for 1m



Có, nên thêm MinerU vào resume_parser, nhưng chỉ dùng nó làm tầng đọc tài liệu/OCR. MinerU không tự hiểu đâu là kỹ năng, kinh nghiệm hay học vấn và cũng không chấm độ phù hợp với JD.
Luồng phù hợp:
PDF/DOCX
   ↓
MinerU: OCR + đọc bố cục + thứ tự nội dung
   ↓
content_list.json / Markdown
   ↓
CV Normalizer: chuyển thành JSON nghiệp vụ
   ↓
AI Scoring: so sánh CV với rubric của JD
Kiến trúc đề xuất
resume_parser/
├── mineru_adapter       # Gọi MinerU API
├── document_detector    # PDF text, PDF scan, DOCX...
├── content_normalizer   # Chuẩn hóa output MinerU
├── cv_extractor         # Trích thông tin nghiệp vụ bằng LLM
├── schema_validator     # Kiểm tra JSON
└── quality_checker      # Độ tin cậy và cảnh báo thiếu dữ liệu
JSON cuối cùng của hệ thống nên có dạng:
{
  "personal_info": {
    "full_name": "Nguyễn Văn A",
    "email": "candidate@example.com",
    "phone_number": "090..."
  },
  "summary": "...",
  "skills": [
    {
      "name": "Laravel",
      "evidence": "Phát triển hệ thống ERP bằng Laravel",
      "source_page": 2
    }
  ],
  "work_experience": [],
  "education": [],
  "certifications": [],
  "projects": [],
  "extraction_confidence": 0.91,
  "warnings": []
}
Nên dùng output nào?
Ưu tiên:
content_list.json: nội dung đơn giản, đúng thứ tự đọc, dễ tích hợp.
Markdown: tiện đưa vào LLM.
middle.json: chỉ giữ để debug bố cục.
Chưa phụ thuộc trực tiếp vào content_list_v2.json vì tài liệu MinerU ghi đây vẫn là định dạng đang phát triển.
Nên viết một mineru_adapter để sau này MinerU thay đổi cấu trúc output thì chỉ cần sửa một module.
Cách chạy phù hợp với hệ thống hiện tại
Nên chạy MinerU thành service riêng trên máy local:
Website/VPS
   ↓ Tailscale
resume-backend local
   ↓ nội bộ
MinerU service local
Không nên cài chung MinerU vào container resume-backend, vì dependency và model khá nặng. Tách riêng giúp:
Khởi động và nâng cấp độc lập.
MinerU lỗi không làm chết resume-backend.
Giới hạn CPU/RAM dễ hơn.
Có thể chuyển sang máy có GPU sau này.
Với CV thông thường, nên bắt đầu bằng backend pipeline:
Chạy được bằng CPU.
Phù hợp PDF có text và CV scan cơ bản.
Ít tốn tài nguyên hơn VLM/hybrid.
MinerU hiện yêu cầu tối thiểu khoảng 16 GB RAM và khoảng 20 GB ổ đĩa cho triển khai đầy đủ.
Nếu máy có GPU từ khoảng 8 GB VRAM và CV thường có bố cục phức tạp, có thể thử hybrid để tăng độ chính xác.
Tối ưu hiệu năng
Không cần gửi mọi tài liệu qua MinerU:
PDF có text đơn giản
→ đọc nhanh bằng PyMuPDF/PyPDF

PDF scan, lỗi font, nhiều cột, nhiều bảng
→ MinerU

DOCX
→ MinerU native hoặc python-docx
Đây là cách tốt hơn việc bắt MinerU xử lý toàn bộ CV.
Lưu ý quan trọng
MinerU chỉ đọc tài liệu, không được dùng trực tiếp output của nó làm điểm CV.
Không để AI tự điền thông tin MinerU không nhận dạng được.
Mỗi kỹ năng/kinh nghiệm phải lưu bằng chứng và số trang nguồn.
CV chứa dữ liệu cá nhân; nên chạy MinerU offline, mã hóa file và xóa file tạm sau khi xử lý.
Phiên bản hiện tại sử dụng giấy phép MinerU Open Source License dựa trên Apache 2.0. Nếu cung cấp dịch vụ online cho người dùng, giấy phép yêu cầu công khai việc hệ thống có sử dụng MinerU.
Kết luận: MinerU rất phù hợp làm “mắt đọc tài liệu” cho resume_parser; còn cv_extractor và candidate_scoring vẫn phải là module riêng. Đây là kiến trúc mình khuyến nghị cho resume-backend của bạn.