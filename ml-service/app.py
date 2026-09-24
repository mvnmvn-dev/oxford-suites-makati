"""
Oxford Suites RMS — TensorFlow Inference Service
Collaborative Filtering recommendations + Time Series inventory forecast.
Falls back to NumPy heuristics if TensorFlow is unavailable.
"""
from __future__ import annotations

import json
import os
from datetime import datetime
from pathlib import Path
from typing import Any

import numpy as np
from flask import Flask, jsonify, request
from flask_cors import CORS

app = Flask(__name__)
CORS(app)

ROOT = Path(__file__).resolve().parent
MODELS = ROOT / "models"
DATA = ROOT / "data"
MODELS.mkdir(exist_ok=True)
DATA.mkdir(exist_ok=True)

TF_AVAILABLE = False
try:
    import tensorflow as tf  # noqa: F401

    TF_AVAILABLE = True
except Exception:
    TF_AVAILABLE = False


def load_menu_catalog() -> list[dict[str, Any]]:
    path = DATA / "menu_catalog.json"
    if path.exists():
        return json.loads(path.read_text(encoding="utf-8"))
    # Seed catalog aligned with MySQL demo menu
    catalog = [
        {"id": 1, "name": "Suite Garden Salad", "category": "starters"},
        {"id": 2, "name": "Truffle Mushroom Soup", "category": "starters"},
        {"id": 3, "name": "Prawn Cocktail", "category": "starters"},
        {"id": 4, "name": "Oxford Short Rib", "category": "mains"},
        {"id": 5, "name": "Pan-Seared Sea Bass", "category": "mains"},
        {"id": 6, "name": "Suite Chicken Supreme", "category": "mains"},
        {"id": 7, "name": "Wild Mushroom Risotto", "category": "mains"},
        {"id": 8, "name": "Wagyu Ribeye 300g", "category": "grill"},
        {"id": 9, "name": "Lamb Chops", "category": "grill"},
        {"id": 10, "name": "Dark Chocolate Fondant", "category": "desserts"},
        {"id": 11, "name": "Yuzu Cheesecake", "category": "desserts"},
        {"id": 12, "name": "Suite Old Fashioned", "category": "bar"},
        {"id": 13, "name": "Makati Spritz", "category": "bar"},
    ]
    path.write_text(json.dumps(catalog, indent=2), encoding="utf-8")
    return catalog


def build_or_load_cf_matrix(n_users: int = 20, n_items: int = 13) -> np.ndarray:
    path = MODELS / "cf_matrix.npy"
    if path.exists():
        return np.load(path)
    rng = np.random.default_rng(42)
    # Synthetic anonymous preference matrix (privacy-by-design — no PII)
    matrix = rng.random((n_users, n_items))
    # Bias popular suite dishes
    matrix[:, [1, 3, 4, 7, 9]] += 0.35
    matrix = np.clip(matrix, 0, 1)
    np.save(path, matrix)
    return matrix


def collaborative_filtering(customer_id: int, limit: int = 5) -> list[dict[str, Any]]:
    catalog = load_menu_catalog()
    matrix = build_or_load_cf_matrix(n_items=len(catalog))
    user_idx = abs(customer_id) % matrix.shape[0]

    if TF_AVAILABLE:
        # Simple embedding-style scoring via TF matmul (demo-scale CF)
        import tensorflow as tf

        user_vec = tf.constant(matrix[user_idx], dtype=tf.float32)
        item_matrix = tf.constant(matrix.T, dtype=tf.float32)
        # similarity of this user to item affinity = user_vec * avg co-occurrence
        scores = tf.linalg.matvec(item_matrix, tf.reduce_mean(item_matrix, axis=1))
        scores = scores + user_vec
        ranked = tf.argsort(scores, direction="DESCENDING").numpy().tolist()
    else:
        user_vec = matrix[user_idx]
        # Cosine similarity against other users → weighted item scores
        norms = np.linalg.norm(matrix, axis=1, keepdims=True) + 1e-8
        sim = (matrix @ user_vec) / (norms.ravel() * (np.linalg.norm(user_vec) + 1e-8))
        scores = sim @ matrix
        ranked = np.argsort(-scores).tolist()

    out = []
    for idx in ranked[:limit]:
        if idx >= len(catalog):
            continue
        item = catalog[idx]
        out.append(
            {
                "id": item["id"],
                "name": item["name"],
                "category": item["category"],
                "score": float(matrix[user_idx, idx]),
                "technique": "collaborative_filtering",
            }
        )
    return out


def time_series_forecast(days: int = 7) -> list[dict[str, Any]]:
    path = DATA / "inventory_usage.json"
    if path.exists():
        series = json.loads(path.read_text(encoding="utf-8"))
    else:
        rng = np.random.default_rng(7)
        names = [
            "Short Rib (kg)",
            "Sea Bass Fillet",
            "Wagyu Ribeye",
            "Arborio Rice",
            "Heavy Cream",
            "Heirloom Greens",
            "Dark Chocolate 70%",
            "Bourbon",
            "Prosecco",
            "Free-range Chicken",
        ]
        series = {}
        for i, name in enumerate(names):
            base = 1.2 + (i % 4) * 0.4
            hist = [round(float(base + rng.normal(0, 0.25)), 3) for _ in range(30)]
            series[name] = {
                "inventory_item_id": i + 1,
                "unit": "kg" if i < 7 else "bottle",
                "current_qty": round(float(8 + rng.random() * 20), 2),
                "reorder_level": round(float(3 + rng.random() * 5), 2),
                "daily_usage": hist,
            }
        path.write_text(json.dumps(series, indent=2), encoding="utf-8")

    forecasts = []
    for name, meta in series.items():
        usage = np.array(meta["daily_usage"], dtype=float)
        if TF_AVAILABLE:
            import tensorflow as tf

            # Tiny 1D conv / moving average stand-in for time-series model
            x = tf.constant(usage.reshape(1, -1, 1), dtype=tf.float32)
            kernel = tf.constant([[[0.1]], [[0.2]], [[0.4]], [[0.2]], [[0.1]]], dtype=tf.float32)
            smoothed = tf.nn.conv1d(x, kernel, stride=1, padding="SAME")
            level = float(tf.reduce_mean(smoothed).numpy())
            trend = float((usage[-1] - usage[0]) / max(len(usage), 1))
        else:
            level = float(np.mean(usage[-7:]))
            trend = float((usage[-1] - usage[-7]) / 7) if len(usage) >= 7 else 0.0

        qty = float(meta["current_qty"])
        projection = []
        for d in range(1, days + 1):
            qty = max(0.0, qty - max(0.05, level + trend * d * 0.1))
            projection.append({"day": d, "projected_qty": round(qty, 2)})

        forecasts.append(
            {
                "inventory_item_id": meta["inventory_item_id"],
                "name": name,
                "unit": meta["unit"],
                "current_qty": float(meta["current_qty"]),
                "reorder_level": float(meta["reorder_level"]),
                "avg_daily_use": round(level, 3),
                "suggested_reorder": projection[-1]["projected_qty"] <= float(meta["reorder_level"]),
                "projection": projection,
                "technique": "time_series_analysis",
            }
        )
    return forecasts


@app.get("/health")
def health():
    return jsonify(
        {
            "status": "ok",
            "service": "tensorflow-inference",
            "tensorflow": TF_AVAILABLE,
            "time": datetime.utcnow().isoformat() + "Z",
        }
    )


@app.get("/recommend")
def recommend():
    customer_id = int(request.args.get("customer_id", 0))
    limit = int(request.args.get("limit", 5))
    return jsonify(
        {
            "recommendations": collaborative_filtering(customer_id, limit),
            "source": "ml-service",
            "tensorflow": TF_AVAILABLE,
            "privacy": "anonymous_preference_vectors",
        }
    )


@app.get("/forecast")
def forecast():
    days = int(request.args.get("days", 7))
    return jsonify(
        {
            "forecast": time_series_forecast(days),
            "source": "ml-service",
            "tensorflow": TF_AVAILABLE,
        }
    )


if __name__ == "__main__":
    port = int(os.environ.get("ML_PORT", "5001"))
    app.run(host="0.0.0.0", port=port, debug=True)
