import React from 'react';
import '../styles/ProductCarousel.scss';

const ProductCarousel = ({ products }) => {
    if (!products || products.length === 0) return null;
    console.log("Products received:", products);
    return (
        <div className="product-carousel">
            <div className="product-grid">
                {products.map(product => (
                    <div key={product.id} className="product-card">
                        {product.image && (
                            <img
                                src={product.image}
                                alt={product.name}
                                className="product-image"
                                loading="eager"
                            />
                        )}
                        <h3 className="product-title">{product.name}</h3>
                        <p className="product-price">{product.price}</p>
                        <a
                            href={product.url}
                            className="product-link"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            View Product
                        </a>
                    </div>
                ))}
            </div>
        </div>
    );
};

export default ProductCarousel;