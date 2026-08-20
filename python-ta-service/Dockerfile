# ─── Python TA Service — Dockerfile ───────────────────────────────────────────
# Uses Python 3.11-slim for a small, production-ready image.
# Avoids all the "pip can't compile X on the server" headaches by pinning
# binary-friendly versions and using --no-cache-dir.

FROM python:3.11-slim

# System deps needed by some Python packages (scipy, pandas, scikit-learn)
RUN apt-get update && apt-get install -y --no-install-recommends \
    gcc \
    g++ \
    libgomp1 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Copy requirements first (layer cache — only reinstalls if requirements change)
COPY requirements.txt .

# Install Python deps
RUN pip install --no-cache-dir --upgrade pip \
    && pip install --no-cache-dir -r requirements.txt

# Copy the rest of the service
COPY . .

# Expose the FastAPI port
EXPOSE 8001

# Start uvicorn in production mode
CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8001", "--workers", "2"]
