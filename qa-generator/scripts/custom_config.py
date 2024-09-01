from pathlib import Path

#This system prompt is passed in training_data after the Q/A is formated for fine tuning. 
SYSTEM_PROMPT = "You are an AI assistant specialized in providing information about various repositories and their contents. Answer user queries accurately and concisely based on the information provided in the repository's README files and other documentation."

# Folder paths
RAW_DATA_DIR = Path('./raw_data')  # Readme downloaded will be saved here
TEMP_DATA_DIR = Path('./temp_data')  # Parsed raw data is saved here while extracting Q/A
OUTPUT_DATA_DIR = Path('./output_data/')  # All Extracted QA is saved here
TRAINING_DATA_DIR = Path('./training_data')  # Extrated QA is formatted suitable for fineTuning.
TRAINING_DATA_FILE = 'training_data.jsonl'

# Path to the file containing the generated Q/A pairs that will be formatted for finetuning
FINETUNE_INPUT_FILE = Path('./output_data/output_data.json')
# Path to the file where the formatted training data for finetuning will be saved
FINETUNE_OUTPUT_FILE = Path('./training_data/training_data.jsonl')

# Other constants
N_RETRIES = 3  # Example constant for the number of retries

MAX_CONTEXT_LINES = 20

