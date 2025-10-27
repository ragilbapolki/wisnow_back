from fastapi import FastAPI, Request
import google.generativeai as genai
import psycopg2
from psycopg2 import pool
import os
import logging
from dotenv import load_dotenv

# ==============================================
# Konfigurasi dasar
# ==============================================
load_dotenv()  # load .env file

app = FastAPI(title="Wisnow Knowledge Base Chatbot")

# Logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Konfigurasi Gemini API
genai.configure(api_key=os.getenv("GEMINI_API_KEY"))

# ==============================================
# Koneksi Database (Connection Pool)
# ==============================================
try:
    db_pool = pool.SimpleConnectionPool(
        1, 10,
        dbname=os.getenv("DB_DATABASE", "dashboard"),
        user=os.getenv("DB_USERNAME", "postgres"),
        password=os.getenv("DB_PASSWORD", "root"),
        host=os.getenv("DB_HOST", "localhost"),
        port=os.getenv("DB_PORT", "5432")
    )
    logger.info("Database pool berhasil dibuat ✅")
except Exception as e:
    logger.error(f"Gagal membuat pool database: {e}")
    db_pool = None


# ==============================================
# Fungsi mengambil data artikel
# ==============================================
def get_db_articles():
    if not db_pool:
        raise Exception("Database pool belum siap.")

    conn = db_pool.getconn()
    try:
        cur = conn.cursor()
        cur.execute("""
            SELECT title, content
            FROM wisnow.articles
            WHERE deleted_at IS NULL AND status = 'published' AND visibility = 'public'
        """)
        rows = cur.fetchall()
        cur.close()
        return [f"{r[0]}: {r[1]}" for r in rows if r[1] is not None]
    except Exception as e:
        logger.error(f"Error saat mengambil artikel: {e}")
        return []
    finally:
        db_pool.putconn(conn)


# ==============================================
# Endpoint utama chatbot
# ==============================================
@app.post("/chat")
async def chat(request: Request):
    body = await request.json()
    question = body.get("question", "").strip()
    print(f"PERTANYAAN: {question}")

    if not question:
        return {"error": "Pertanyaan kosong"}

    return {"answer": f"Kamu bertanya: {question}"}



# ==============================================
# Endpoint root
# ==============================================
@app.get("/")
def root():
    return {"message": "Wisnow Chatbot API aktif 🚀"}
