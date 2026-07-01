import argparse
import json
from pathlib import Path

from sentence_transformers import InputExample, SentenceTransformer, losses
from torch.utils.data import DataLoader


def _format_text(item):
    if isinstance(item, str):
        return item.strip()
    if isinstance(item, dict):
        parts = []
        for key in ("job_title", "description", "requirements"):
            value = item.get(key)
            if value:
                parts.append(f"{key}: {str(value).strip()}")
        if parts:
            return "\n".join(parts)
        return json.dumps(item, ensure_ascii=False)
    return str(item).strip()


def load_examples(data_path, loss_type="triplet"):
    raw = json.loads(Path(data_path).read_text(encoding="utf-8-sig"))
    if isinstance(raw, dict):
        records = raw.get("hr_recruitment_triplets", [])
    elif isinstance(raw, list):
        records = raw
    else:
        raise ValueError("JSON phai la list hoac object chua key 'hr_recruitment_triplets'.")

    examples = []
    for record in records:
        anchor = _format_text(record.get("anchor", ""))
        positive = _format_text(record.get("positive", ""))
        negative = _format_text(record.get("negative", ""))

        if not anchor or not positive:
            continue
        if loss_type == "triplet":
            if not negative:
                continue
            examples.append(InputExample(texts=[anchor, positive, negative]))
        else:
            examples.append(InputExample(texts=[anchor, positive]))

    if not examples:
        raise ValueError("Khong tao duoc mau train nao tu du lieu dau vao.")
    return examples


def main():
    parser = argparse.ArgumentParser(description="Fine-tune mxbai-embed-large voi Sentence-Transformers")
    parser.add_argument("--model-name", default="mixedbread-ai/mxbai-embed-large-v1")
    parser.add_argument("--data-path", default="./training/data/train_data.json")
    parser.add_argument("--output-path", default="./mxbai-cv-tuned")
    parser.add_argument("--epochs", type=int, default=3)
    parser.add_argument("--batch-size", type=int, default=8)
    parser.add_argument("--warmup-steps", type=int, default=100)
    parser.add_argument("--loss-type", default="triplet", choices=["triplet", "mnr"])
    args = parser.parse_args()

    print(f"[1/5] Load model goc: {args.model_name}")
    model = SentenceTransformer(args.model_name)

    print(f"[2/5] Doc du lieu train tu: {args.data_path}")
    train_data = load_examples(args.data_path, loss_type=args.loss_type)
    print(f"[3/5] So mau train hop le: {len(train_data)}")

    effective_batch_size = min(args.batch_size, len(train_data))
    min_batch = 2 if args.loss_type == "mnr" else 1
    if effective_batch_size < min_batch:
        if args.loss_type == "mnr":
            raise ValueError("Can toi thieu 2 mau de train voi MultipleNegativesRankingLoss.")
        raise ValueError("Khong du du lieu de train voi TripletLoss.")

    train_dataloader = DataLoader(
        train_data,
        shuffle=True,
        batch_size=effective_batch_size,
        drop_last=False,
    )

    if args.loss_type == "triplet":
        train_loss = losses.TripletLoss(model)
    else:
        train_loss = losses.MultipleNegativesRankingLoss(model)

    print("[4/5] Bat dau fine-tuning...")
    model.fit(
        train_objectives=[(train_dataloader, train_loss)],
        epochs=args.epochs,
        warmup_steps=args.warmup_steps,
        output_path=args.output_path,
    )

    print(f"[5/5] Hoan tat. Model da duoc luu vao: {args.output_path}")


if __name__ == "__main__":
    main()
