#!/usr/bin/env python3
"""
NSFW 图片审核推理服务（imgbed local_nsfw 驱动对接端）

约定接口（与 imgbed LocalNsfwDriver 对齐）：
  POST /            body: {"image_base64": "..."}
  响应:             {"score": 0-100, "label": "..."}    # score 越高越可能是 NSFW

模型：Falconsai/nsfw_image_detection（HuggingFace，首次启动自动下载约 300MB）
部署：
  pip install -r requirements.txt
  python app.py                     # 默认 0.0.0.0:8900
  # 或 gunicorn -w 1 -b 0.0.0.0:8900 app:app   （GPU 机器别开多 worker，模型吃显存）

图床侧配置：后台「广告与站点」→ 全局审核驱动选 local_nsfw，
并往 img_settings 写入 nsfw_endpoint（如 http://127.0.0.1:8900/）。
"""
from base64 import b64decode
from io import BytesIO

from flask import Flask, jsonify, request
from PIL import Image
from transformers import pipeline

app = Flask(__name__)

# 启动即加载模型（CPU 可跑，单图约 0.3-1s；有 GPU 自动用）
classifier = pipeline(
    "image-classification",
    model="Falconsai/nsfw_image_detection",
)

THRESHOLD = 70  # score >= 70 判 NSFW（与图床侧阈值保持一致）


@app.route("/", methods=["POST"])
def audit():
    try:
        data = request.get_json(force=True) or {}
        raw = b64decode(data.get("image_base64", ""))
        img = Image.open(BytesIO(raw)).convert("RGB")
    except Exception as e:
        return jsonify({"error": f"bad image: {e}"}), 400

    preds = classifier(img)  # [{label, score}, ...]
    normal = next((p for p in preds if p["label"] == "normal"), None)
    nsfw = next((p for p in preds if p["label"] != "normal"), None)

    # 归一到 0-100 的 NSFW 得分
    nsfw_score = int(round((nsfw["score"] if nsfw else 0.0) * 100))
    label = (nsfw or normal or {"label": "unknown"})["label"]

    return jsonify({"score": nsfw_score, "label": label})


@app.route("/health", methods=["GET"])
def health():
    return jsonify({"ok": True, "model": "Falconsai/nsfw_image_detection", "threshold": THRESHOLD})


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=8900)
