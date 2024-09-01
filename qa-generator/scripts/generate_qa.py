import os
import json
import asyncio
from collections import defaultdict
from tqdm.asyncio import tqdm
from tenacity import retry, stop_after_attempt, wait_exponential, retry_if_exception_type, RetryError
import typing
import openai
import argparse

from custom_config import TEMP_DATA_DIR, MAX_CONTEXT_LINES

# Fetch the OpenAI API key from the environment
openai.api_key = os.getenv("OPENAI_API_KEY")

if openai.api_key is None:
    raise ValueError("OpenAI API key not found. Please set the OPENAI_API_KEY environment variable.")

class RateLimitError(Exception):
    pass

# Create a semaphore to limit the number of concurrent API calls
MAX_CALLS_PER_SECOND = 5  # Set your desired maximum calls per second
semaphore = asyncio.Semaphore(MAX_CALLS_PER_SECOND)

@retry(
    stop=stop_after_attempt(5),
    wait=wait_exponential(multiplier=1, min=4, max=60),
    retry=retry_if_exception_type(RateLimitError)
)
async def make_openai_call(prompt: str, model: str = "gpt-4o-mini") -> typing.Optional[str]:
    async with semaphore:  # Acquire the semaphore to ensure rate limiting
        try:
            response = await openai.ChatCompletion.acreate(
                model=model,
                messages=[{"role": "user", "content": prompt}],
                max_tokens=2048,
                n=1,
                temperature=0.7,
            )
            return response.choices[0].message['content']
        except openai.error.RateLimitError as e:
            print(f"Rate limit error: {e}. Retrying...")
            raise RateLimitError()
        except openai.error.OpenAIError as e:
            print(f"OpenAI API error: {e}")
            return None

def extract_code_and_context(content: str, context_window: int = 2, max_context_lines: int = 10) -> typing.List[typing.Tuple[str, str, int, int, typing.Optional[str]]]:
    lines = content.splitlines()
    code_snippets = []
    current_section = None

    for i, line in enumerate(lines):
        if line.startswith('#'):
            current_section = line.strip('#').strip()

        if line.strip().startswith('```'):
            snippet_lines = []
            j = i + 1
            while j < len(lines) and not lines[j].strip().startswith('```'):
                snippet_lines.append(lines[j])
                j += 1
            snippet = '\n'.join(snippet_lines)

            context_start = max(0, i - max_context_lines)
            context_end = min(len(lines), j + context_window + 1)
            context = '\n'.join(lines[context_start:context_end])
            code_snippets.append((snippet, context, i, j, current_section))

    return code_snippets

def extract_sections(content: str) -> typing.Dict[str, str]:
    sections = defaultdict(str)
    lines = content.splitlines()
    current_section = None

    for line in lines:
        if line.startswith('#'):
            current_section = line.strip('#').strip()
        elif current_section:
            sections[current_section] += line.strip() + ' '

    sections = {k: v.strip() for k, v in sections.items() if v.strip()}
    return sections

def is_question_valid(question: typing.Optional[str]) -> bool:
    if question is None:
        return False
    invalid_indicators = [
        "generate a question",
        "what is the title",
        "what is the section",
        "Please provide me",
    ]
    return not any(indicator in question.lower() for indicator in invalid_indicators)

def is_answer_valid(answer: typing.Optional[str]) -> bool:
    if answer is None:
        return False
    missing_indicators = [
        "not included in the text", 
        "I couldn't find the answer", 
        "The answer is not present",
        "Please provide the section",
        "I need the content",
        "Please provide me",
    ]
    return not any(indicator in answer.lower() for indicator in missing_indicators)

async def generate_question(section_title: str, text: str) -> typing.Optional[str]:
    question_prompt = (
        f"Create a single question based solely on the content of the following README section titled '{section_title}':\n\n"
        f"{text}\n\n"
        f"Output only the question."
    )
    return await make_openai_call(question_prompt)

async def generate_snippet_question(section_title: str, snippet: str, text: str) -> typing.Optional[str]:
    question_prompt = (
        "Given the following code snippet and its context, generate a question that would help someone "
        "understand the purpose, functionality, or structure of the code. The question should be something "
        "that a developer might ask when trying to learn or understand how to implement this code. "
        "Consider what the snippet is doing and how it might be used in a real-world scenario.\n\n"
        "Context:\n"
        f"{text}\n\n"
        "Code Snippet:\n"
        f"{snippet}\n\n"
        "Question:"
    )
    return await make_openai_call(question_prompt)

async def generate_answer(question: str, section_title: str, text: str) -> typing.Optional[str]:
    answer_prompt = (
        f"Based on the following section from a README file titled '{section_title}', provide a detailed answer to the question:\n\n"
        f"{text}\n\n"
        f"Question: {question}\n"
        f"Just output the answer directly."
    )
    return await make_openai_call(answer_prompt)

async def generate_snippet_answer(section_title: str, snippet: str, context: str, question: str) -> typing.Optional[str]:
    answer_prompt = (
        "Given the following context, code snippet, and question, generate an answer that directly addresses "
        "the question. The answer should explain the code snippet's role, its output, or how it works "
        "within the context provided. Focus on clarity and detail, as if explaining to a peer who is new to this concept.\n\n"
        "Context:\n"
        f"{context}\n\n"
        "Code Snippet:\n"
        f"{snippet}\n\n"
        "Question:\n"
        f"{question}\n\n"
        "Answer:"
    )
    return await make_openai_call(answer_prompt)

def estimate_question_count(text: str) -> int:
    word_count = len(text.split())
    if word_count < 50:
        return 1
    elif word_count < 150:
        return 2
    else:
        return 3

async def process_section(section_title: str, text: str, num_questions: int) -> typing.List[typing.Dict[str, str]]:
    qa_pairs = []

    for _ in range(num_questions):
        question = await generate_question(section_title, text)
        if question and is_question_valid(question):
            answer = await generate_answer(question, section_title, text)
            if answer and is_answer_valid(answer):
                qa_pairs.append({
                    "section_title": section_title,
                    "question": question,
                    "answer": answer
                })
    
    return qa_pairs

async def process_snippet(snippet: str, context: str, current_section: str) -> typing.List[typing.Dict[str, str]]:
    qa_pairs = []
    question = await generate_snippet_question("Code Snippet", snippet, context)
    
    if question and is_question_valid(question):
        answer = await generate_snippet_answer("Code Snippet", snippet, context, question)
        if answer and is_answer_valid(answer):
            qa_pairs.append({
                "context": context,
                "section_title": current_section,
                "code_snippet": snippet,
                "question": question,
                "answer": answer
            })
    
    return qa_pairs

async def generate_questions_answers(content: str, output_dir: str = TEMP_DATA_DIR) -> typing.List[typing.Dict[str, str]]:
    questions_answers = []

    snippets_with_context = extract_code_and_context(content, context_window=2, max_context_lines=MAX_CONTEXT_LINES)
    sections = extract_sections(content)

    # Retry failed questions first
    reattempted_qa_pairs = await retry_failed_questions()
    questions_answers.extend(reattempted_qa_pairs)

    # Process sections in parallel using asyncio.gather
    section_tasks = [
        process_section(section_title, text, estimate_question_count(text))
        for section_title, text in sections.items()
    ]
    for section_qa_pairs in asyncio.as_completed(section_tasks):
        questions_answers.extend(await section_qa_pairs)

    # Process snippets in parallel using asyncio.gather
    snippet_tasks = [
        process_snippet(snippet, context, current_section)
        for snippet, context, _, _ , current_section in snippets_with_context
    ]
    for snippet_qa_pairs in asyncio.as_completed(snippet_tasks):
        questions_answers.extend(await snippet_qa_pairs)

    return questions_answers

def load_checkpoint(output_file: str) -> typing.Tuple[typing.Set[str], typing.Set[typing.Tuple[str, str]]]:
    if os.path.exists(output_file):
        with open(output_file, 'r') as f:
            processed_data = [json.loads(line) for line in f]
        processed_titles = {entry['section_title'] for entry in processed_data if 'section_title' in entry}
        processed_snippets = {(entry['context'], entry['code_snippet']) for entry in processed_data if 'code_snippet' in entry}
        return processed_titles, processed_snippets
    return set(), set()

def log_failed_question(identifier: str, question: str, q_type: str):
    log_file = "failed_questions.jsonl"
    with open(log_file, 'a') as log:
        log.write(json.dumps({"type": q_type, "identifier": identifier, "question": question}) + '\n')

async def retry_failed_questions() -> typing.List[typing.Dict[str, str]]:
    log_file = "failed_questions.jsonl"
    if not os.path.exists(log_file):
        return []

    reattempted_qa_pairs = []
    with open(log_file, 'r') as log:
        failed_questions = [json.loads(line) for line in log]

    with open(log_file, 'w') as log:  # Clear the log file after processing
        pass

    for entry in failed_questions:
        if entry['type'] == "section":
            question = entry['question']
            if question != "No question generated":
                answer = await generate_answer(question, entry['identifier'], "")
                if answer:
                    reattempted_qa_pairs.append({
                        "section_title": entry['identifier'],
                        "question": question,
                        "answer": answer
                    })
                else:
                    log_failed_question(entry['identifier'], question, "section")  # Re-log if still failing
            else:
                log_failed_question(entry['identifier'], question, "section")  # Log if question couldn't be generated
        elif entry['type'] == "snippet":
            question = entry['question']
            if question != "No question generated":
                answer = await generate_answer(question, "Code Snippet", "")
                if answer:
                    reattempted_qa_pairs.append({
                        "code_snippet": entry['identifier'],
                        "question": question,
                        "answer": answer
                    })
                else:
                    log_failed_question(entry['identifier'], question, "snippet")  # Re-log if still failing
            else:
                log_failed_question(entry['identifier'], question, "snippet")  # Log if question couldn't be generated

    return reattempted_qa_pairs

def generate_qa_from_content(post_content: str) -> str:
    questions_answers = asyncio.run(generate_questions_answers(post_content))
    return json.dumps(questions_answers)

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Generate Q/A from post content.")
    parser.add_argument('--content', type=str, required=True, help='The content of the post to generate Q/A from')
    args = parser.parse_args()

    # Generate Q/A from the provided content
    qa_result = generate_qa_from_content(args.content)
    print(qa_result)
