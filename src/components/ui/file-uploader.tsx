import React, { useCallback } from 'react';
import { useDropzone } from 'react-dropzone';
import { UploadIcon } from 'lucide-react';
import { __ } from '@wordpress/i18n';

export const FileUploader = ({ accept, maxSize, onUpload }) => {
    const onDrop = useCallback((acceptedFiles) => {
        if (acceptedFiles?.[0]) {
            onUpload(acceptedFiles[0]);
        }
    }, [onUpload]);

    const { getRootProps, getInputProps, isDragActive } = useDropzone({
        onDrop,
        accept: {
            'application/pdf': ['.pdf']
        },
        maxSize: maxSize * 1024 * 1024, // Convert MB to bytes
        multiple: false
    });

    return (
        <div
            {...getRootProps()}
            className={`border-2 border-dashed rounded-lg p-6 text-center cursor-pointer transition-colors
                ${isDragActive ? 'border-primary bg-primary/10' : 'border-gray-300 hover:border-primary'}`}
        >
            <input {...getInputProps()} />
            <UploadIcon className="mx-auto h-8 w-8 text-gray-400 mb-2" />
            <p className="text-sm text-gray-600">
                {isDragActive
                    ? __('Drop the file here...', 'wish-cart')
                    : __('Drag & drop a PDF file here, or click to select', 'wish-cart')}
            </p>
            <p className="text-xs text-gray-500 mt-1">
                {__('Maximum file size:', 'wish-cart')} {maxSize}MB
            </p>
        </div>
    );
};